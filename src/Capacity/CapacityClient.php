<?php

declare(strict_types=1);

namespace Brewless\Laravel\Capacity;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Container\Container;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use JsonException;
use SensitiveParameter;

/**
 * Asks Brewless, while the application runs, for more vCPU on the
 * environment's Serverless SQL Database for a while: a ticket sale at ten, a
 * newsletter that goes out at noon. Only when the owner switched the capacity
 * key on in the console, which gives every container `BREWLESS_API_URL`,
 * `BREWLESS_ENVIRONMENT` and `BREWLESS_CAPACITY_KEY`.
 *
 * Every request is signed with that key like the databases client does: an
 * HMAC-SHA256 over the method, the path, the time and the SHA-256 of the
 * body, each on a line of its own. The key itself is never sent, logged or
 * put in an exception.
 *
 * Brewless or Scaleway out of reach, busy (5xx) or throttling (429) is tried
 * again after half a second, a second and two seconds, each time signed anew:
 * Brewless refuses a signature it saw before. That is safe because a boost
 * key makes one boost, however often it is sent.
 */
final readonly class CapacityClient implements CapacityApi
{
    /**
     * What Brewless takes as a boost key: 1 to 100 letters, digits, dots, colons, underscores and dashes,
     * and not only dots (the key is part of the path).
     */
    private const string KEY = '/^(?!\.+$)[A-Za-z0-9._:-]{1,100}$/D';

    private const string PATH = '/api/runtime/capacity';

    /**
     * @param  list<int>  $retryMilliseconds  how long to wait before each try after the first
     */
    public function __construct(
        private Factory $http,
        private ?string $url,
        private ?string $environment,
        #[SensitiveParameter]
        private ?string $key,
        private int $timeout = 10,
        private int $connectTimeout = 5,
        private array $retryMilliseconds = [500, 1000, 2000],
    ) {}

    /**
     * Swap the client for an in-memory one in the application's own tests: nothing is sent, every boost is
     * kept, and the fake has assertions. Code that type-hints CapacityApi (or resolves it) gets the fake.
     */
    public static function fake(): FakeCapacity
    {
        $fake = new FakeCapacity;

        Container::getInstance()->instance(CapacityApi::class, $fake);

        return $fake;
    }

    public function configured(): bool
    {
        return $this->url !== null && $this->url !== '' && $this->environment !== null && $this->environment !== '' && $this->key !== null && $this->key !== '';
    }

    public function databases(): array
    {
        $response = $this->send('GET', '/databases');

        if (! $response->successful()) {
            throw $this->refused($response);
        }

        $data = $response->json('data');

        if (! is_array($data)) {
            throw CapacityException::unexpectedAnswer($response->status());
        }

        return array_values(array_map(DatabaseCapacity::fromAnswer(...), array_filter($data, is_array(...))));
    }

    public function schedule(string $key, DateTimeInterface $starts, DateTimeInterface $ends, int $min, int $max, int $prewarm = 15, int $cooldown = 30, ?string $name = null, ?string $database = null): Boost
    {
        return $this->boostFrom($this->send('POST', '/boosts', array_filter([
            'key' => $this->checked($key),
            'database' => $database,
            'name' => $name,
            'min_vcpu' => $min,
            'max_vcpu' => $max,
            'starts_at' => self::time($starts),
            'ends_at' => self::time($ends),
            'prewarm_minutes' => $prewarm,
            'cooldown_minutes' => $cooldown,
        ], static fn (mixed $value): bool => $value !== null)));
    }

    public function boost(string $key, int $minutes, int $min, int $max, ?string $name = null, ?string $database = null): Boost
    {
        return $this->boostFrom($this->send('POST', '/boosts', array_filter([
            'key' => $this->checked($key),
            'database' => $database,
            'name' => $name,
            'min_vcpu' => $min,
            'max_vcpu' => $max,
            'minutes' => $minutes,
        ], static fn (mixed $value): bool => $value !== null)));
    }

    public function reschedule(string $key, DateTimeInterface $starts, DateTimeInterface $ends): Boost
    {
        return $this->update($key, ['starts_at' => $starts, 'ends_at' => $ends]);
    }

    public function update(string $key, array $changes): Boost
    {
        $body = array_map(static fn (string|int|DateTimeInterface $value): string|int => $value instanceof DateTimeInterface ? self::time($value) : $value, $changes);

        return $this->boostFrom($this->send('PATCH', '/boosts/'.$this->checked($key), $body));
    }

    public function status(string $key): ?Boost
    {
        $response = $this->send('GET', '/boosts/'.$this->checked($key));

        return $response->status() === 404 ? null : $this->boostFrom($response);
    }

    public function ready(string $key): bool
    {
        return $this->status($key)?->ready === true;
    }

    public function extend(string $key, DateTimeInterface $until): Boost
    {
        return $this->boostFrom($this->send('POST', '/boosts/'.$this->checked($key).'/extend', ['ends_at' => self::time($until)]));
    }

    public function cancel(string $key): Boost
    {
        return $this->boostFrom($this->send('DELETE', '/boosts/'.$this->checked($key)));
    }

    /**
     * The signature Brewless checks.
     */
    public static function signature(string $method, string $path, string $timestamp, string $body, #[SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', $method."\n".$path."\n".$timestamp."\n".hash('sha256', $body), $key);
    }

    /**
     * @param  array<string, mixed>|null  $payload  sent as JSON; null sends no body
     *
     * @throws CapacityException
     */
    private function send(string $method, string $path, ?array $payload = null): Response
    {
        if (! $this->configured()) {
            throw CapacityUnavailable::notConfigured();
        }

        try {
            $body = $payload === null ? '' : json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw CapacityException::unreadable($exception->getMessage());
        }

        $path = self::PATH.$path;
        $url = rtrim((string) $this->url, '/').$path;
        $previous = 0;
        $tries = 0;

        while (true) {
            // A new timestamp for every try, and never the one of the try before, so no two tries carry
            // the same signature: Brewless refuses a signature it already saw.
            $timestamp = max(Carbon::now()->getTimestamp(), $previous + 1);
            $previous = $timestamp;
            $tries++;
            $failure = null;

            try {
                $request = $this->http
                    ->timeout($this->timeout)
                    ->connectTimeout($this->connectTimeout)
                    ->acceptJson()
                    ->withHeaders([
                        'X-Brewless-Environment' => (string) $this->environment,
                        'X-Brewless-Timestamp' => (string) $timestamp,
                        'X-Brewless-Signature' => self::signature($method, $path, (string) $timestamp, $body, (string) $this->key),
                    ]);

                if ($payload !== null) {
                    $request = $request->withBody($body, 'application/json');
                }

                $response = $request->send($method, $url);

                if (! $response->serverError() && $response->status() !== 429) {
                    return $response;
                }

                $reason = 'it answered '.$response->status();
            } catch (ConnectionException $exception) {
                $reason = 'no connection';
                $failure = $exception;
            }

            if ($tries > count($this->retryMilliseconds)) {
                throw CapacityUnreachable::because($reason, $tries, $failure);
            }

            Sleep::for($this->retryMilliseconds[$tries - 1])->milliseconds();
        }
    }

    /**
     * @throws CapacityException
     */
    private function boostFrom(Response $response): Boost
    {
        if (! $response->successful()) {
            throw $this->refused($response);
        }

        $data = $response->json('data');

        return is_array($data) ? Boost::fromAnswer($data, $response->status()) : throw CapacityException::unexpectedAnswer($response->status());
    }

    private function refused(Response $response): CapacityRefused
    {
        $message = $response->json('message');
        $errors = $response->json('errors');

        return CapacityRefused::answered($response->status(), is_string($message) ? $message : null, is_array($errors) ? $errors : []);
    }

    /**
     * @throws CapacityException
     */
    private function checked(string $key): string
    {
        return preg_match(self::KEY, $key) === 1 ? $key : throw CapacityException::invalidKey($key);
    }

    /** ISO 8601 with the offset of the time given, as Brewless takes it: 2027-05-01T10:00:00+02:00. */
    private static function time(DateTimeInterface $time): string
    {
        return CarbonImmutable::instance($time)->toIso8601String();
    }
}
