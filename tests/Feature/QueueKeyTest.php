<?php

declare(strict_types=1);

use Brewless\Laravel\BrewlessServiceProvider;
use Brewless\Laravel\Tests\TestCase;

uses(TestCase::class);

test('the key of a queue at the provider goes to the sqs connection and nowhere else', function (): void {
    config([
        'queue.connections.sqs' => ['driver' => 'sqs', 'key' => 'files-key', 'secret' => 'files-secret', 'prefix' => 'https://sqs.example/project-1', 'queue' => 'shop'],
        'filesystems.disks.s3.key' => 'files-key',
        'brewless.queue' => ['key' => 'queue-key', 'secret' => 'queue-secret', 'endpoint' => 'https://sqs.mnq.fr-par.scaleway.com'],
    ]);

    (new BrewlessServiceProvider(app()))->register();

    expect(config('queue.connections.sqs'))->toMatchArray([
        'driver' => 'sqs',
        'key' => 'queue-key',
        'secret' => 'queue-secret',
        'endpoint' => 'https://sqs.mnq.fr-par.scaleway.com',
        'prefix' => 'https://sqs.example/project-1',
        'queue' => 'shop',
    ])->and(config('filesystems.disks.s3.key'))->toBe('files-key');
});

test('without a key of its own the sqs connection is left as the application configured it', function (): void {
    config([
        'queue.connections.sqs' => ['driver' => 'sqs', 'key' => 'aws-key', 'secret' => 'aws-secret'],
        'brewless.queue' => ['key' => null, 'secret' => null, 'endpoint' => 'https://sqs.mnq.fr-par.scaleway.com'],
    ]);

    (new BrewlessServiceProvider(app()))->register();

    expect(config('queue.connections.sqs'))->toBe(['driver' => 'sqs', 'key' => 'aws-key', 'secret' => 'aws-secret']);
});
