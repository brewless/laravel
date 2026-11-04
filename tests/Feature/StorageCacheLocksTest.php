<?php

declare(strict_types=1);

use Brewless\Laravel\Cache\LockingStorageStore;
use Brewless\Laravel\Tests\TestCase;
use Illuminate\Cache\StorageStore;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(TestCase::class);

/*
 * The `storage` cache keeps its values in a bucket that every container reads.
 * Laravel's own store of it has no locks, so Cache::lock(), a ShouldBeUnique
 * job and WithoutOverlapping failed on it; spatie/laravel-og-image did.
 */

beforeEach(function (): void {
    if (! class_exists(StorageStore::class)) {
        $this->markTestSkipped('The storage cache came with Laravel 13.');
    }

    Storage::fake('s3');
    config([
        'cache.default' => 'storage',
        'cache.stores.storage' => ['driver' => 'storage', 'disk' => 's3', 'path' => 'framework/cache/data', 'lock_path' => sys_get_temp_dir().'/brewless-locks-'.getmypid()],
    ]);
    Cache::forgetDriver('storage');
});

test('the storage cache keeps its values in the bucket', function (): void {
    Cache::put('greeting', 'hello', 60);

    expect(Cache::get('greeting'))->toBe('hello')
        ->and(Cache::getStore())->toBeInstanceOf(LockingStorageStore::class)
        ->and(Storage::disk('s3')->allFiles('framework/cache/data'))->not->toBeEmpty();
});

test('the storage cache takes a lock, as a page that makes an image once does', function (): void {
    expect(Cache::getStore())->toBeInstanceOf(LockProvider::class);

    $made = Cache::lock('og-image-generate:page', 10)->block(5, fn (): string => 'made');

    expect($made)->toBe('made');
});

test('a lock that is held is not given twice, and is free again once released', function (): void {
    $first = Cache::lock('import', 10);

    expect($first->get())->toBeTrue()
        ->and(Cache::lock('import', 10)->get())->toBeFalse();

    $first->release();

    expect(Cache::lock('import', 10)->get())->toBeTrue();
});

test('a lock is restored by its owner, as a unique job releases it later', function (): void {
    $lock = Cache::lock('unique-job', 60);
    $lock->get();

    Cache::restoreLock('unique-job', $lock->owner())->release();

    expect(Cache::lock('unique-job', 60)->get())->toBeTrue();
});

test('the locks stay off the bucket: Object Storage cannot hold one', function (): void {
    Cache::lock('somewhere', 10)->get();

    $locks = array_filter(Storage::disk('s3')->allFiles(), static fn (string $path): bool => str_contains($path, 'lock'));

    expect($locks)->toBe([])
        ->and(glob(config('cache.stores.storage.lock_path').'/*/*/*') ?: [])->not->toBeEmpty();
});
