<?php

declare(strict_types=1);

use Brewless\Laravel\BrewlessServiceProvider;
use Brewless\Laravel\Mail\MailEvent;
use Brewless\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

uses(TestCase::class);

/*
 * POST /_ops/mail-events: Scaleway's topic reporting what became of a mail.
 * The fixture is a real notification Scaleway signed (a mail to an address
 * without a mailbox, dropped), with the real certificate it names.
 */

function droppedNotification(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../fixtures/sns-email-dropped.json'), true);
}

function listenToTopic(string $topic): void
{
    config(['brewless.mail.events.topic' => $topic]);
    app()->getProvider(BrewlessServiceProvider::class)?->boot();
    app('router')->getRoutes()->refreshNameLookups();
}

function fakeCertificate(?string $pem = null): void
{
    Http::fake([
        'messaging.s3.fr-par.scw.cloud/*' => Http::response($pem ?? (string) file_get_contents(__DIR__.'/../fixtures/sns-certificate.pem')),
        'sns.mnq.fr-par.scaleway.com/*' => Http::response('<ConfirmSubscriptionResponse/>'),
    ]);
}

function postNotification(array $message): TestResponse
{
    return test()->call('POST', '/_ops/mail-events', [], [], [], ['CONTENT_TYPE' => 'text/plain; charset=UTF-8'], (string) json_encode($message));
}

test('a notification scaleway signed becomes a mail event, with what became of the mail', function (): void {
    Event::fake([MailEvent::class]);
    fakeCertificate();
    $message = droppedNotification();
    listenToTopic($message['TopicArn']);

    postNotification($message)->assertOk()->assertJson(['ok' => true]);

    Event::assertDispatched(MailEvent::class, fn (MailEvent $event): bool => $event->type === 'email_dropped'
        && $event->to === 'nobody@mailtest.brewless.dev'
        && $event->from === 'hello@mailtest.brewless.dev'
        && str_contains((string) $event->error, 'no reachable MX found')
        && $event->bounced()
        && ! $event->complained());
});

test('a notification that was changed, of another topic, or signed by a certificate from elsewhere is refused', function (callable $change): void {
    Event::fake([MailEvent::class]);
    fakeCertificate();
    $message = droppedNotification();
    listenToTopic($message['TopicArn']);

    postNotification($change($message))->assertForbidden();

    Event::assertNotDispatched(MailEvent::class);
})->with([
    'changed' => [fn (array $message): array => [...$message, 'Message' => str_replace('email_dropped', 'email_delivered', $message['Message'])]],
    'another topic' => [fn (array $message): array => [...$message, 'TopicArn' => 'arn:scw:sns:fr-par:project-x:other']],
    'certificate elsewhere' => [fn (array $message): array => [...$message, 'SigningCertURL' => 'https://evil.example/cert.pem']],
    'no signature' => [fn (array $message): array => array_diff_key($message, ['Signature' => true])],
]);

test('a subscription asked for by the topic is confirmed, and only at scaleway', function (): void {
    $key = openssl_pkey_new(['private_key_bits' => 2048]);
    $certificate = openssl_csr_sign(openssl_csr_new(['commonName' => 'sns.test'], $key), null, $key, 1);
    openssl_x509_export($certificate, $pem);
    fakeCertificate($pem);
    listenToTopic('arn:scw:sns:fr-par:project-1:shop-production-mail-events');

    $confirmation = [
        'Type' => 'SubscriptionConfirmation',
        'MessageId' => 'm-1',
        'Token' => 'token-1',
        'TopicArn' => 'arn:scw:sns:fr-par:project-1:shop-production-mail-events',
        'Message' => 'You have chosen to subscribe to the topic.',
        'SubscribeURL' => 'https://sns.mnq.fr-par.scaleway.com/?Action=ConfirmSubscription&Token=token-1',
        'Timestamp' => '2026-10-27T08:00:00Z',
        'SignatureVersion' => '1',
        'SigningCertURL' => 'https://messaging.s3.fr-par.scw.cloud/fr-par/sns/test.pem',
    ];
    $signed = '';

    foreach (['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'] as $field) {
        $signed .= $field."\n".$confirmation[$field]."\n";
    }

    openssl_sign($signed, $signature, $key, OPENSSL_ALGO_SHA1);

    postNotification([...$confirmation, 'Signature' => base64_encode($signature)])->assertOk();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sns.mnq.fr-par.scaleway.com/?Action=ConfirmSubscription&Token=token-1');

    // A confirmation that sends the application elsewhere is not followed.
    $elsewhere = [...$confirmation, 'SubscribeURL' => 'https://evil.example/confirm'];
    $signed = '';

    foreach (['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'] as $field) {
        $signed .= $field."\n".$elsewhere[$field]."\n";
    }

    openssl_sign($signed, $signature, $key, OPENSSL_ALGO_SHA1);

    postNotification([...$elsewhere, 'Signature' => base64_encode($signature)])->assertForbidden();
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'evil.example'));
});

test('without a topic there is no such route', function (): void {
    $this->post('/_ops/mail-events', [])->assertNotFound();
});
