<?php

declare(strict_types=1);

namespace Brewless\Laravel\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;

/**
 * Keeps a copy of every mail the application sends, headers and content as
 * it went out, in the bucket of the environment that Brewless set up for it
 * (config `brewless.mail.copies`). The copy is named by the id Scaleway
 * Transactional Email queued the mail as, from its SMTP answer, so the
 * Brewless console can find it next to what Scaleway knows of the mail.
 *
 * A mail without such an id (another mailer, the log mailer) gets no copy.
 * Storing it never stops a mail: a failure is logged, and the mail is sent.
 */
final class MailCopies
{
    public static function configured(): bool
    {
        foreach (['bucket', 'endpoint', 'region', 'key', 'secret'] as $setting) {
            $value = config('brewless.mail.copies.'.$setting);

            if (! is_string($value) || $value === '') {
                return false;
            }
        }

        return true;
    }

    public function keep(MessageSent $event): void
    {
        $sent = $event->sent->getSymfonySentMessage();

        if (preg_match('/queued as ([0-9a-f-]{36})/i', $sent->getDebug(), $match) !== 1) {
            return;
        }

        $id = strtolower($match[1]);
        $body = $sent->toString();
        $bucket = (string) config('brewless.mail.copies.bucket');
        $url = rtrim((string) config('brewless.mail.copies.endpoint'), '/').'/'.rawurlencode($bucket).'/'.$id.'.eml';

        try {
            $response = app(Factory::class)
                ->timeout((int) config('brewless.mail.copies.timeout', 10))
                ->withHeaders(S3Signature::put($url, $body, (string) config('brewless.mail.copies.region'), (string) config('brewless.mail.copies.key'), (string) config('brewless.mail.copies.secret'), 'message/rfc822'))
                ->withBody($body, 'message/rfc822')
                ->put($url);

            if ($response->failed()) {
                Log::warning('Brewless could not keep a copy of a mail.', ['id' => $id, 'status' => $response->status()]);
            }
        } catch (ConnectionException) {
            Log::warning('Brewless could not keep a copy of a mail.', ['id' => $id, 'status' => 'unreachable']);
        }
    }
}
