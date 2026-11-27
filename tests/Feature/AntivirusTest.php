<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use Brewless\Laravel\Facades\Antivirus;
use Brewless\Laravel\Malware\AntivirusApi;
use Brewless\Laravel\Malware\FakeAntivirus;
use Brewless\Laravel\Malware\FileNotFound;
use Brewless\Laravel\Malware\MalwareException;
use Brewless\Laravel\Malware\ScanRequestRefused;
use Brewless\Laravel\Malware\ScanResult;
use Brewless\Laravel\Tests\TestCase;
use Carbon\CarbonImmutable;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Http\Message\RequestInterface;

uses(TestCase::class);

/*
 * Brewless Malware Protection read through the application's own S3 disk.
 * The bucket is an in-memory stand-in behind the disk's own S3 client: it
 * answers HeadObject and GetObjectTagging for the objects a test puts in it.
 */

const MALWARE_KEY = 'bWFsd2FyZS1rZXktZm9yLXRlc3RzLTMyLWJ5dGVzLSEhIQ==';

const MALWARE_ENVIRONMENT = '01k8malwareenvironment0001';

final class FakeBucket
{
    /** @var array<string, array{etag: string, tags: array<string, string>}> */
    public array $objects = [];

    /** @var list<array{name: string, bucket: string, key: string}> */
    public array $calls = [];

    public ?string $refuse = null;

    /** @param  array<string, string>  $tags */
    public function put(string $key, string $etag, array $tags = []): self
    {
        $this->objects[$key] = ['etag' => $etag, 'tags' => $tags];

        return $this;
    }

    /** Tags as the scanner writes them, for the ETag it scanned. */
    public function scanned(string $key, string $etag, string $status, string $scannedEtag, array $own = []): self
    {
        return $this->put($key, $etag, [...$own, 'brewless-av' => $status, 'brewless-av-etag' => $scannedEtag, 'brewless-av-at' => '2026-11-27T20:01:12Z']);
    }

    public function __invoke(CommandInterface $command, RequestInterface $request): PromiseInterface
    {
        $key = (string) $command['Key'];
        $this->calls[] = ['name' => $command->getName(), 'bucket' => (string) $command['Bucket'], 'key' => $key];

        if ($this->refuse !== null) {
            return Create::rejectionFor(new S3Exception('Access Denied', $command, ['code' => $this->refuse, 'response' => new Response(403)]));
        }

        $object = $this->objects[$key] ?? null;

        if ($object === null) {
            return Create::rejectionFor(new S3Exception('Not Found', $command, ['code' => $command->getName() === 'HeadObject' ? 'NotFound' : 'NoSuchKey', 'response' => new Response(404)]));
        }

        return Create::promiseFor(match ($command->getName()) {
            'HeadObject' => new Result(['ETag' => '"'.$object['etag'].'"']),
            'GetObjectTagging' => new Result(['TagSet' => array_map(static fn (string $key, string $value): array => ['Key' => $key, 'Value' => $value], array_keys($object['tags']), array_values($object['tags']))]),
            default => throw new LogicException('The fake bucket does not answer '.$command->getName()),
        });
    }
}

function bucket(string $disk = 's3'): FakeBucket
{
    $bucket = new FakeBucket;
    Storage::disk($disk)->getClient()->getHandlerList()->setHandler($bucket);

    return $bucket;
}

function s3Disk(string $root = ''): array
{
    return [
        'driver' => 's3',
        'key' => 'SCWTESTACCESSKEY',
        'secret' => 'test-secret-key',
        'region' => 'fr-par',
        'bucket' => 'shop-uploads',
        'endpoint' => 'https://s3.fr-par.scw.cloud',
        'use_path_style_endpoint' => true,
        'root' => $root,
    ];
}

beforeEach(function (): void {
    config([
        'filesystems.default' => 's3',
        'filesystems.disks.s3' => s3Disk(),
        'filesystems.disks.tenants' => s3Disk('tenant-1'),
        'filesystems.disks.local' => ['driver' => 'local', 'root' => sys_get_temp_dir()],
        'brewless.malware.url' => 'https://acme.brewless.eu/',
        'brewless.malware.environment' => MALWARE_ENVIRONMENT,
        'brewless.malware.key' => MALWARE_KEY,
    ]);

    Http::preventStrayRequests();
});

test('a file whose tag was written for its etag now has that status', function (): void {
    bucket()->scanned('avatars/1.png', '9b2c', 'clean', '9b2c');

    $result = Antivirus::disk('s3')->status('avatars/1.png');

    expect($result)->toBeInstanceOf(ScanResult::class)
        ->and($result->isClean())->toBeTrue()
        ->and($result->isPending())->toBeFalse()
        ->and($result->status())->toBe('clean')
        ->and($result->etag())->toBe('9b2c')
        ->and($result->signature())->toBeNull()
        ->and($result->scannedAt()?->toIso8601ZuluString())->toBe('2026-11-27T20:01:12Z');
});

test('each status the scanner writes reads as that status', function (string $status, string $method): void {
    bucket()->scanned('report.pdf', 'abc-3', $status, '"abc-3"', ['customer' => 'own-tag']);

    $result = Antivirus::disk('s3')->status('report.pdf');

    expect($result->status())->toBe($status)
        ->and($result->{$method}())->toBeTrue();

    foreach (['isClean', 'isInfected', 'isPending', 'isUnsupported', 'isFailed'] as $other) {
        if ($other !== $method) {
            expect($result->{$other}())->toBeFalse($other);
        }
    }
})->with([
    'clean' => ['clean', 'isClean'],
    'infected' => ['infected', 'isInfected'],
    'unsupported' => ['unsupported', 'isUnsupported'],
    'failed' => ['failed', 'isFailed'],
]);

test('an object written again after its scan is pending, never clean', function (): void {
    // The tag of the scan is still there, for the ETag of the version before.
    bucket()->scanned('avatars/1.png', 'new-etag', 'clean', 'old-etag');

    $result = Antivirus::disk('s3')->status('avatars/1.png');

    expect($result->isPending())->toBeTrue()
        ->and($result->isClean())->toBeFalse()
        ->and($result->etag())->toBe('new-etag')
        ->and($result->scannedAt())->toBeNull();
});

test('a file without the tags, or with a status the scanner does not write, is pending', function (array $tags): void {
    bucket()->put('avatars/1.png', '9b2c', $tags);

    expect(Antivirus::disk('s3')->status('avatars/1.png')->isPending())->toBeTrue();
})->with([
    'no tags' => [[]],
    'only the customer\'s own' => [['customer' => 'own-tag']],
    'a status without its etag' => [['brewless-av' => 'clean']],
    'an empty etag' => [['brewless-av' => 'clean', 'brewless-av-etag' => '']],
    'a status the scanner does not write' => [['brewless-av' => 'scanning', 'brewless-av-etag' => '9b2c']],
]);

test('the disk\'s root is put in front of the path, as flysystem does', function (string $path): void {
    $bucket = bucket('tenants')->scanned('tenant-1/avatars/1.png', '9b2c', 'infected', '9b2c');

    expect(Antivirus::disk('tenants')->status($path)->isInfected())->toBeTrue()
        ->and($bucket->calls)->toBe([
            ['name' => 'HeadObject', 'bucket' => 'shop-uploads', 'key' => 'tenant-1/avatars/1.png'],
            ['name' => 'GetObjectTagging', 'bucket' => 'shop-uploads', 'key' => 'tenant-1/avatars/1.png'],
        ]);
})->with(['avatars/1.png', '/avatars/1.png']);

test('the methods of the facade work on the default disk', function (): void {
    config(['filesystems.default' => 'tenants']);
    bucket('tenants')->scanned('tenant-1/a.txt', 'e1', 'clean', 'e1');

    expect(Antivirus::status('a.txt')->isClean())->toBeTrue()
        ->and(resolve(AntivirusApi::class)->status('a.txt')->isClean())->toBeTrue();
});

test('a file that is not there throws file not found', function (): void {
    bucket();

    expect(static fn () => Antivirus::disk('s3')->status('gone.png'))->toThrow(FileNotFound::class, 'gone.png');
});

test('a bucket that cannot be read throws, with the error code and never the key', function (): void {
    bucket()->refuse = 'AccessDenied';

    try {
        Antivirus::disk('s3')->status('avatars/1.png');
        $this->fail('Expected an exception.');
    } catch (MalwareException $exception) {
        expect($exception)->not->toBeInstanceOf(FileNotFound::class)
            ->and($exception->getMessage())->toContain('AccessDenied', '403')
            ->and($exception->getMessage())->not->toContain('test-secret-key', 'SCWTESTACCESSKEY');
    }
});

test('a disk that is not an s3 disk has no scan status', function (): void {
    expect(static fn () => Antivirus::disk('local'))->toThrow(MalwareException::class, '"local" is not an S3 disk');
});

test('asking for a scan posts the bucket and the full key, signed like the capacity client', function (): void {
    Carbon::setTestNow(CarbonImmutable::createFromTimestamp(1793000000));
    Http::fake(['acme.brewless.eu/*' => Http::response(['ok' => true], 202)]);

    Antivirus::disk('tenants')->requestScan('avatars/1.png');

    Http::assertSent(static function (Request $request): bool {
        $body = '{"bucket":"shop-uploads","key":"tenant-1/avatars/1.png"}';
        $expected = hash_hmac('sha256', "POST\n/api/runtime/malware/scans\n1793000000\n".hash('sha256', $body), MALWARE_KEY);

        return $request->method() === 'POST'
            && $request->url() === 'https://acme.brewless.eu/api/runtime/malware/scans'
            && $request->body() === $body
            && $request->header('Content-Type') === ['application/json']
            && $request->header('Accept') === ['application/json']
            && $request->header('X-Brewless-Environment') === [MALWARE_ENVIRONMENT]
            && $request->header('X-Brewless-Timestamp') === ['1793000000']
            && $request->header('X-Brewless-Signature') === [$expected]
            && ! str_contains(json_encode($request->headers()).$request->body(), MALWARE_KEY);
    });
    Http::assertSentCount(1);
});

test('without the three variables nothing is sent', function (string $missing): void {
    config(['brewless.malware.'.$missing => null]);
    Http::fake();

    expect(static fn () => Antivirus::requestScan('a.txt'))->toThrow(MalwareException::class, 'BREWLESS_MALWARE_KEY');

    Http::assertNothingSent();
})->with(['url', 'environment', 'key']);

test('a refusal is not tried again and says what brewless answered', function (): void {
    Sleep::fake();
    Http::fake(['*' => Http::response(['message' => 'Brewless does not protect this bucket.'], 404)]);

    try {
        Antivirus::requestScan('a.txt');
        $this->fail('Expected a refusal.');
    } catch (ScanRequestRefused $refused) {
        expect($refused->status)->toBe(404)
            ->and($refused->getMessage())->toContain('does not protect this bucket')
            ->and($refused->getMessage())->not->toContain(MALWARE_KEY);
    }

    Http::assertSentCount(1);
    Sleep::assertNeverSlept();
});

test('brewless busy is tried again, signed anew, and then unreachable', function (): void {
    Carbon::setTestNow(CarbonImmutable::createFromTimestamp(1793000000));
    Sleep::fake();
    Http::fake(['*' => Http::response(['message' => 'down'], 503)]);

    expect(static fn () => Antivirus::requestScan('a.txt'))->toThrow(MalwareException::class, 'after 4 tries');

    $timestamps = [];
    Http::assertSent(static function (Request $request) use (&$timestamps): bool {
        $timestamps[] = $request->header('X-Brewless-Timestamp')[0];

        return true;
    });
    expect($timestamps)->toBe(['1793000000', '1793000001', '1793000002', '1793000003']);
    Sleep::assertSequence([Sleep::for(500)->milliseconds(), Sleep::for(1000)->milliseconds(), Sleep::for(2000)->milliseconds()]);
});

test('waiting looks again more slowly until the file is scanned', function (): void {
    Carbon::setTestNow(CarbonImmutable::createFromTimestamp(1793000000));
    $bucket = bucket()->put('a.txt', 'e1');
    $looks = 0;
    Sleep::fake(syncWithCarbon: true);
    Sleep::whenFakingSleep(static function () use ($bucket, &$looks): void {
        // After the third look the scanner has been.
        if (++$looks === 3) {
            $bucket->scanned('a.txt', 'e1', 'infected', 'e1');
        }
    });

    $result = Antivirus::wait('a.txt', 60);

    expect($result->isInfected())->toBeTrue();
    Sleep::assertSequence([Sleep::for(1000)->milliseconds(), Sleep::for(2000)->milliseconds(), Sleep::for(4000)->milliseconds()]);
});

test('waiting never takes longer than the timeout, and gives pending when it ran out', function (): void {
    Carbon::setTestNow($start = CarbonImmutable::createFromTimestamp(1793000000));
    bucket()->put('a.txt', 'e1');
    Sleep::fake(syncWithCarbon: true);

    $result = Antivirus::wait('a.txt', 20);

    expect($result->isPending())->toBeTrue()
        ->and(Carbon::now()->getTimestampMs() - $start->getTimestampMs())->toBe(20000);
    // 1 + 2 + 4 + 8 seconds, then only the 5 that were left.
    Sleep::assertSequence([
        Sleep::for(1000)->milliseconds(), Sleep::for(2000)->milliseconds(), Sleep::for(4000)->milliseconds(),
        Sleep::for(8000)->milliseconds(), Sleep::for(5000)->milliseconds(),
    ]);
});

test('waiting with no time looks once', function (): void {
    bucket()->put('a.txt', 'e1');
    Sleep::fake();

    expect(Antivirus::wait('a.txt', 0)->isPending())->toBeTrue();
    Sleep::assertNeverSlept();
});

test('an upload is released once the clean copy is at its path and gone from the prefix', function (): void {
    $bucket = bucket()->put('incoming/avatars/1.png', 'e1');

    // Held: waiting for the scanner, or held for good (unsupported, failed).
    expect(Antivirus::release('avatars/1.png'))->toBeFalse();

    // Found clean: the scanner copied it to the path, tagged the copy and removed the one under the prefix.
    unset($bucket->objects['incoming/avatars/1.png']);
    $bucket->scanned('avatars/1.png', 'e1', 'clean', 'e1');

    expect(Antivirus::release('avatars/1.png'))->toBeTrue();
});

test('an upload that was taken away, or a path holding another version, is not released', function (Closure $arrange): void {
    $arrange(bucket());

    expect(Antivirus::release('avatars/1.png'))->toBeFalse();
})->with([
    'infected: gone from the prefix, and never at the path' => [static fn (FakeBucket $bucket) => $bucket],
    'the path holds another version, not scanned' => [static fn (FakeBucket $bucket) => $bucket->scanned('avatars/1.png', 'new', 'clean', 'old')],
    'still held while an older clean copy is at the path' => [static fn (FakeBucket $bucket) => $bucket->put('incoming/avatars/1.png', 'e2')->scanned('avatars/1.png', 'e1', 'clean', 'e1')],
]);

test('a disk with a root releases from the prefix in front of the whole key', function (): void {
    $bucket = bucket('tenants')->put('incoming/tenant-1/a.txt', 'e1');

    expect(Antivirus::disk('tenants')->release('a.txt'))->toBeFalse()
        ->and($bucket->calls[0]['key'])->toBe('incoming/tenant-1/a.txt');
});

test('the staging path puts the prefix in front, as configured', function (string $prefix, string $expected): void {
    config(['brewless.malware.staging_prefix' => $prefix]);

    expect(Antivirus::stagingPath('/avatars/1.png'))->toBe($expected);
})->with([
    'the default' => ['incoming/', 'incoming/avatars/1.png'],
    'without a slash' => ['held', 'held/avatars/1.png'],
    'with slashes around' => ['/uploads/held/', 'uploads/held/avatars/1.png'],
]);

test('a disk with a root has no staging path', function (): void {
    expect(static fn () => Antivirus::disk('tenants')->stagingPath('a.txt'))->toThrow(MalwareException::class, 'has a `root`');
});

test('the fake answers what the test marked, per disk, and pending for the rest', function (): void {
    $fake = Antivirus::fake();
    $fake->markClean('a.png')->markInfected('b.png')->markUnsupported('c.zip')->markFailed('d.bin')->markClean('e.png', disk: 'tenants');

    expect($fake)->toBeInstanceOf(FakeAntivirus::class)
        ->and(resolve(AntivirusApi::class))->toBe($fake)
        ->and(Antivirus::status('a.png')->isClean())->toBeTrue()
        ->and(Antivirus::disk('s3')->status('/a.png')->isClean())->toBeTrue()
        ->and(Antivirus::status('b.png')->isInfected())->toBeTrue()
        ->and(Antivirus::status('b.png')->signature())->toBe('Eicar-Test-Signature')
        ->and(Antivirus::status('c.zip')->isUnsupported())->toBeTrue()
        ->and(Antivirus::status('d.bin')->isFailed())->toBeTrue()
        ->and(Antivirus::status('e.png')->isPending())->toBeTrue()
        ->and(Antivirus::disk('tenants')->status('e.png')->isClean())->toBeTrue()
        ->and(Antivirus::wait('nothing.png')->isPending())->toBeTrue()
        ->and(Antivirus::release('a.png'))->toBeTrue()
        ->and(Antivirus::release('b.png'))->toBeFalse()
        ->and(Antivirus::stagingPath('a.png'))->toBe('incoming/a.png');

    $fake->markPending('a.png');
    expect(Antivirus::status('a.png')->isPending())->toBeTrue();
});

test('the fake says which scans were asked for', function (): void {
    $fake = Antivirus::fake();
    $fake->assertNothingRequested();

    Antivirus::requestScan('a.png');
    Antivirus::disk('tenants')->requestScan('b.png');

    $fake->assertScanRequested('a.png');
    $fake->assertScanRequested('b.png', 'tenants');
    $fake->assertScanNotRequested('b.png');
    expect($fake->requested())->toBe([['disk' => 's3', 'path' => 'a.png'], ['disk' => 'tenants', 'path' => 'b.png']])
        ->and(static fn () => $fake->assertScanRequested('c.png'))->toThrow(AssertionFailedError::class, 'No scan of "c.png"')
        ->and(static fn () => $fake->assertNothingRequested())->toThrow(AssertionFailedError::class, 's3:a.png, tenants:b.png');
});
