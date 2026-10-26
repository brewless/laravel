<?php

declare(strict_types=1);

namespace Brewless\Laravel\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;

/**
 * Whether a message of Topics and Events (Scaleway's SNS) was signed by
 * Scaleway: signature version 1, RSA with SHA1 over the fields SNS signs,
 * with the certificate at SigningCertURL, which has to be on a host of
 * `brewless.mail.events.certificate_hosts` over HTTPS.
 */
final class SnsSignature
{
    /** @var array<string, string> certificates read in this process, by URL */
    private static array $certificates = [];

    /**
     * @param  array<string, mixed>  $message
     */
    public static function valid(array $message): bool
    {
        $url = $message['SigningCertURL'] ?? null;
        $signature = is_string($message['Signature'] ?? null) ? base64_decode($message['Signature'], true) : false;

        if (($message['SignatureVersion'] ?? null) !== '1' || ! is_string($url) || ! is_string($signature) || ! self::trustedHost($url)) {
            return false;
        }

        $certificate = self::certificate($url);
        $key = $certificate === null ? false : openssl_pkey_get_public($certificate);

        return $key !== false && openssl_verify(self::signed($message), $signature, $key, OPENSSL_ALGO_SHA1) === 1;
    }

    /**
     * The fields SNS signs, in its order, each name and value on a line of its own.
     *
     * @param  array<string, mixed>  $message
     */
    private static function signed(array $message): string
    {
        $fields = ($message['Type'] ?? null) === 'Notification'
            ? ['Message', 'MessageId', 'Subject', 'Timestamp', 'TopicArn', 'Type']
            : ['Message', 'MessageId', 'SubscribeURL', 'Timestamp', 'Token', 'TopicArn', 'Type'];
        $signed = '';

        foreach ($fields as $field) {
            if (isset($message[$field]) && is_string($message[$field])) {
                $signed .= $field."\n".$message[$field]."\n";
            }
        }

        return $signed;
    }

    private static function trustedHost(string $url): bool
    {
        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && in_array(parse_url($url, PHP_URL_HOST), (array) config('brewless.mail.events.certificate_hosts'), true);
    }

    private static function certificate(string $url): ?string
    {
        if (isset(self::$certificates[$url])) {
            return self::$certificates[$url];
        }

        try {
            $response = app(Factory::class)->timeout(5)->get($url);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful() || ! str_contains($response->body(), 'BEGIN CERTIFICATE')) {
            return null;
        }

        return self::$certificates[$url] = $response->body();
    }
}
