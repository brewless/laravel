<?php

declare(strict_types=1);

namespace Brewless\Laravel\Http\Controllers;

use Brewless\Laravel\Mail\MailEvent;
use Brewless\Laravel\Mail\SnsSignature;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * POST /_ops/mail-events: what Scaleway Transactional Email reports of the
 * mails sent, through the topic Brewless subscribed. A message is taken
 * only from that topic and only with Scaleway's signature; the subscription
 * is confirmed when it is asked for, and every notification becomes a
 * MailEvent. Nothing is kept here.
 */
final class MailEventsController
{
    public function __invoke(Request $request, Factory $http): JsonResponse
    {
        // SNS posts JSON as text/plain.
        $message = json_decode($request->getContent(), true);

        if (! is_array($message) || ($message['TopicArn'] ?? null) !== config('brewless.mail.events.topic') || ! SnsSignature::valid($message)) {
            return response()->json(['ok' => false], 403);
        }

        if (($message['Type'] ?? null) === 'SubscriptionConfirmation') {
            return $this->confirm($message, $http);
        }

        if (($message['Type'] ?? null) !== 'Notification' || ! is_string($message['Message'] ?? null)) {
            return response()->json(['ok' => true]);
        }

        $event = json_decode($message['Message'], true);

        if (is_array($event) && is_string($event['type'] ?? null)) {
            MailEvent::dispatch(
                $event['type'],
                is_string($event['email_id'] ?? null) ? $event['email_id'] : '',
                is_string($event['email_to'] ?? null) ? $event['email_to'] : '',
                is_string($event['email_from'] ?? null) ? $event['email_from'] : '',
                is_string($event['email_error'] ?? null) ? $event['email_error'] : null,
                is_string($event['created_at'] ?? null) ? $event['created_at'] : null,
                $event,
            );
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function confirm(array $message, Factory $http): JsonResponse
    {
        $url = $message['SubscribeURL'] ?? null;

        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || ! in_array(parse_url($url, PHP_URL_HOST), (array) config('brewless.mail.events.subscribe_hosts'), true)) {
            return response()->json(['ok' => false], 403);
        }

        try {
            $confirmed = $http->timeout(10)->get($url)->successful();
        } catch (ConnectionException) {
            $confirmed = false;
        }

        if (! $confirmed) {
            Log::warning('Brewless could not confirm the subscription to the mail events.');
        }

        return response()->json(['ok' => $confirmed], $confirmed ? 200 : 502);
    }
}
