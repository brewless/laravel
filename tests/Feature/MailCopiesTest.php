<?php

declare(strict_types=1);

use Brewless\Laravel\BrewlessServiceProvider;
use Brewless\Laravel\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\SentMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage as SymfonySentMessage;
use Symfony\Component\Mime\Email;

uses(TestCase::class);

const TEM_QUEUED = '5afe80bb-6d6c-405d-9ae2-b7de8753bff5';

function sentMail(string $answer): SentMessage
{
    $email = (new Email)->from('hello@shop.example')->to('ann@example.org')->subject('Your order')->text('Thanks!')->html('<p>Thanks!</p>');
    $sent = new SymfonySentMessage($email, Envelope::create($email));
    $sent->appendDebug($answer);

    return new SentMessage($sent);
}

function keepCopies(): void
{
    config(['brewless.mail.copies' => [
        'bucket' => 'shop-production-f7606904-mail',
        'endpoint' => 'https://s3.fr-par.scw.cloud',
        'region' => 'fr-par',
        'key' => 'SCWCOPIESKEY',
        'secret' => 'copies-secret',
        'timeout' => 10,
    ]]);
    app()->getProvider(BrewlessServiceProvider::class)?->boot();
}

test('a mail sent through transactional email is kept in the bucket under the id it was queued as, headers and content as it went out, signed', function (): void {
    Http::fake(['s3.fr-par.scw.cloud/*' => Http::response('', 200)]);
    keepCopies();

    event(new MessageSent(sentMail('[2026-10-26T21:56:27Z] < 250 2.0.0 OK: queued as '.strtoupper(TEM_QUEUED)), []));

    Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
        && $request->url() === 'https://s3.fr-par.scw.cloud/shop-production-f7606904-mail/'.TEM_QUEUED.'.eml'
        && str_contains($request->body(), 'Subject: Your order')
        && str_contains($request->body(), '<p>Thanks!</p>')
        && $request->header('X-Amz-Content-Sha256')[0] === hash('sha256', $request->body())
        && str_starts_with($request->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=SCWCOPIESKEY/')
        && str_contains($request->header('Authorization')[0], '/fr-par/s3/aws4_request, SignedHeaders=content-type;host;x-amz-content-sha256;x-amz-date, Signature=')
        && ! str_contains($request->header('Authorization')[0], 'copies-secret'));
});

test('a mail that was not queued at transactional email gets no copy', function (): void {
    Http::fake();
    keepCopies();

    event(new MessageSent(sentMail('250 OK'), []));

    Http::assertNothingSent();
});

test('a copy that cannot be stored is logged and never stops the mail', function (): void {
    Http::fake(['s3.fr-par.scw.cloud/*' => Http::response('<Error><Code>AccessDenied</Code></Error>', 403)]);
    Log::spy();
    keepCopies();

    event(new MessageSent(sentMail('250 2.0.0 OK: queued as '.TEM_QUEUED), []));

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context === ['id' => TEM_QUEUED, 'status' => 403]);
});

test('without a bucket nothing listens', function (): void {
    Http::fake();

    event(new MessageSent(sentMail('250 2.0.0 OK: queued as '.TEM_QUEUED), []));

    Http::assertNothingSent();
});
