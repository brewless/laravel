<?php

declare(strict_types=1);

namespace Brewless\Laravel\Databases;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Sleep;
use SensitiveParameter;

/**
 * Asks Brewless for a database per tenant of the application, while it
 * runs: a Serverless SQL Database of its own at Scaleway, in the project and
 * region of the environment. Only with `databases` in brewless.yml, which
 * gives every container `BREWLESS_API_URL`, `BREWLESS_ENVIRONMENT` and
 * `BREWLESS_DATABASES_KEY`.
 *
 * Every request is signed with that key: an HMAC-SHA256 over the method, the
 * path, the time and the SHA-256 of the body, each on a line of its own. The
 * key itself is never sent, logged or put in an exception.
 */
final readonly class DatabaseClient
{
    /** What Brewless makes: what PostgreSQL takes unquoted. */
    private const string NAME = '/^[a-z0-9_]{1,63}$/';

    /** How long to wait between two looks at a database that is being made, in seconds. */
    public const int POLL_SECONDS = 2;

    public function __construct(
        private Factory $http,
        private ?string $url,
        private ?string $environment,
        #[SensitiveParameter]
        private ?string $key,
        private int $timeout = 15,
        private int $connectTimeout = 5,
    ) {}

    /**
     * Whether the three variables are there: the environment may ask for databases.
     */
    public function configured(): bool
    {
        return $this->url !== null && $this->url !== '' && $this->environment !== null && $this->environment !== '' && $this->key !== null && $this->key !== '';
    }

    /**
     * Ask for the database; asking again for the same name makes nothing
     * twice. It is usually still being made: see waitUntilReady().
     *
     * @throws DatabaseException
     */
    public function create(string $name): DatabaseInfo
    {
        $response = $this->send('PUT', $name);

        return $response->successful() ? $this->info($response) : throw $this->refused($response);
    }

    /**
     * Where the database stands; null when Brewless did not make one of this
     * name for this environment.
     *
     * @throws DatabaseException
     */
    public function find(string $name): ?DatabaseInfo
    {
        $response = $this->send('GET', $name);

        if ($response->status() === 404) {
            return null;
        }

        return $response->successful() ? $this->info($response) : throw $this->refused($response);
    }

    /**
     * Remove the database at Scaleway, now, with everything in it. One that
     * Brewless does not know (removed before) is fine.
     *
     * @throws DatabaseException
     */
    public function delete(string $name): void
    {
        $response = $this->send('DELETE', $name);

        if (! $response->successful() && $response->status() !== 404) {
            throw $this->refused($response);
        }
    }

    /**
     * Look at the database every two seconds until it is ready and has an
     * address, for at most `$timeoutSeconds` of waiting.
     *
     * @throws DatabaseException
     */
    public function waitUntilReady(string $name, int $timeoutSeconds = 120): DatabaseInfo
    {
        $waited = 0;

        while (true) {
            $database = $this->find($name) ?? throw DatabaseNotReady::gone($name);

            if ($database->isReady()) {
                return $database;
            }

            if ($database->status !== DatabaseInfo::CREATING && $database->status !== DatabaseInfo::READY) {
                throw DatabaseNotReady::failed($name, $database->status);
            }

            if ($waited >= $timeoutSeconds) {
                throw DatabaseNotReady::timedOut($name, $timeoutSeconds);
            }

            Sleep::for(self::POLL_SECONDS)->seconds();
            $waited += self::POLL_SECONDS;
        }
    }

    /**
     * The signature Brewless checks.
     */
    public static function signature(string $method, string $path, string $timestamp, string $body, #[SensitiveParameter] string $key): string
    {
        return hash_hmac('sha256', $method."\n".$path."\n".$timestamp."\n".hash('sha256', $body), $key);
    }

    /**
     * @throws DatabaseException
     */
    private function send(string $method, string $name): Response
    {
        if (! $this->configured()) {
            throw DatabaseException::notConfigured();
        }

        if (preg_match(self::NAME, $name) !== 1) {
            throw DatabaseException::invalidName($name);
        }

        $path = '/api/runtime/databases/'.$name;
        $timestamp = (string) Carbon::now()->getTimestamp();
        $body = '';

        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->acceptJson()
                ->withHeaders([
                    'X-Brewless-Environment' => (string) $this->environment,
                    'X-Brewless-Timestamp' => $timestamp,
                    'X-Brewless-Signature' => self::signature($method, $path, $timestamp, $body, (string) $this->key),
                ])
                ->send($method, rtrim((string) $this->url, '/').$path);
        } catch (ConnectionException $exception) {
            throw BrewlessUnreachable::because('no connection', $exception);
        }

        if ($response->serverError() || $response->status() === 429) {
            throw BrewlessUnreachable::because('it answered '.$response->status());
        }

        return $response;
    }

    private function info(Response $response): DatabaseInfo
    {
        $answer = $response->json();

        return DatabaseInfo::fromAnswer(is_array($answer) ? $answer : []);
    }

    private function refused(Response $response): RequestRefused
    {
        $message = $response->json('message');

        return RequestRefused::answered($response->status(), is_string($message) ? $message : null);
    }
}
