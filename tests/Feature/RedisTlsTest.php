<?php

declare(strict_types=1);

use Brewless\Laravel\BrewlessServiceProvider;
use Brewless\Laravel\Tests\TestCase;

uses(TestCase::class);

const REDIS_TEST_CERT = "-----BEGIN CERTIFICATE-----\nMIIBszCCAVmgAwIBAgIUZm9vYmFy\n-----END CERTIFICATE-----\n";

test('with the certificate of the cluster, the redis connections speak TLS and trust that certificate only', function (): void {
    config([
        'database.redis.default' => ['host' => '10.42.0.8', 'port' => '6379'],
        'database.redis.cache' => ['host' => '10.42.0.8', 'port' => '6379', 'database' => '1'],
        'brewless.redis.ca' => base64_encode(REDIS_TEST_CERT),
    ]);

    (new BrewlessServiceProvider(app()))->register();

    foreach (['default', 'cache'] as $connection) {
        $settings = config('database.redis.'.$connection);

        expect($settings['scheme'])->toBe('tls')
            ->and($settings['host'])->toBe('10.42.0.8')
            ->and($settings['context']['stream'])->toMatchArray(['verify_peer' => true, 'verify_peer_name' => false])
            ->and(file_get_contents($settings['context']['stream']['cafile']))->toBe(REDIS_TEST_CERT);
    }
});

test('without a certificate, or with something that is not one, the redis connections are left as the application configured them', function (?string $ca): void {
    config([
        'database.redis.default' => ['host' => '127.0.0.1', 'port' => '6379'],
        'brewless.redis.ca' => $ca,
    ]);

    (new BrewlessServiceProvider(app()))->register();

    expect(config('database.redis.default'))->toBe(['host' => '127.0.0.1', 'port' => '6379']);
})->with([
    'none' => [null],
    'not base64' => ['%%%'],
    'not a certificate' => [base64_encode('hello')],
]);
