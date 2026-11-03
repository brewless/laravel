<?php

declare(strict_types=1);

namespace Brewless\Laravel\Cache;

use Illuminate\Cache\FileStore;
use Illuminate\Cache\StorageStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\Filesystem as LocalFiles;

/**
 * Laravel's `storage` cache, the values in a bucket every container reads,
 * with locks.
 *
 * Laravel's own StorageStore has no locks, so `Cache::lock()`, a
 * `ShouldBeUnique` job and `WithoutOverlapping` fail on it with "Call to
 * undefined method StorageStore::lock()": spatie/laravel-og-image did on
 * coding.agency, 3 November 2026. Object Storage cannot take a lock, so the
 * lock is Laravel's FileLock on the container's own disk: atomic within a
 * container, not across containers. That keeps a page from making the same
 * image twice at once in one container; work that must run once across the
 * whole environment needs a cache that every container shares, such as Redis.
 */
final class LockingStorageStore extends StorageStore implements LockProvider
{
    /**
     * @param  array<int, class-string>|bool|null  $serializableClasses
     */
    public function __construct(Filesystem $disk, string $directory, string $prefix, private readonly string $lockDirectory, array|bool|null $serializableClasses = null)
    {
        parent::__construct($disk, $directory, $prefix, $serializableClasses);
    }

    /**
     * @param  string  $name
     * @param  int  $seconds
     * @param  string|null  $owner
     */
    public function lock($name, $seconds = 0, $owner = null): Lock
    {
        return $this->locks()->lock($this->getPrefix().$name, $seconds, $owner);
    }

    /**
     * @param  string  $name
     * @param  string  $owner
     */
    public function restoreLock($name, $owner): Lock
    {
        return $this->lock($name, 0, $owner);
    }

    private function locks(): FileStore
    {
        return new FileStore(new LocalFiles, $this->lockDirectory);
    }
}
