<?php

declare(strict_types=1);

namespace Brewless\Laravel\Mail;

use Illuminate\Support\Facades\Date;

/**
 * The headers of one PUT to Object Storage, signed with AWS Signature
 * Version 4 as Scaleway's S3 API asks. Only what MailCopies needs: a body to
 * one key, path-style, without query.
 */
final class S3Signature
{
    /**
     * @return array<string, string>
     */
    public static function put(string $url, string $body, string $region, string $accessKey, #[\SensitiveParameter] string $secretKey, string $contentType): array
    {
        $now = Date::now('UTC');
        $amzDate = $now->format('Ymd\THis\Z');
        $day = $now->format('Ymd');
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $payload = hash('sha256', $body);

        $headers = ['content-type' => $contentType, 'host' => $host, 'x-amz-content-sha256' => $payload, 'x-amz-date' => $amzDate];
        $signed = implode(';', array_keys($headers));
        $canonical = implode("\n", [
            'PUT',
            $path,
            '',
            implode('', array_map(static fn (string $name, string $value): string => $name.':'.trim($value)."\n", array_keys($headers), $headers)),
            $signed,
            $payload,
        ]);

        $scope = $day.'/'.$region.'/s3/aws4_request';
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);

        $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $day, 'AWS4'.$secretKey, true), true), true), true);

        return [
            'Content-Type' => $contentType,
            'X-Amz-Content-Sha256' => $payload,
            'X-Amz-Date' => $amzDate,
            'Authorization' => 'AWS4-HMAC-SHA256 Credential='.$accessKey.'/'.$scope.', SignedHeaders='.$signed.', Signature='.hash_hmac('sha256', $toSign, $key),
        ];
    }
}
