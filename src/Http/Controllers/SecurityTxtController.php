<?php

declare(strict_types=1);

namespace Brewless\Laravel\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

/**
 * `/.well-known/security.txt` (RFC 9116): where someone who found a
 * vulnerability reports it. Only with a contact in `brewless.security.contact`
 * (BREWLESS_SECURITY_CONTACT): a file without one is not valid, so there is
 * none. A file the application has itself, a route of its own or one in
 * public/, comes before this one.
 *
 * It expires a year ahead, counted from the start of today, so the answer is
 * the same all day and the edge may keep it.
 */
final class SecurityTxtController
{
    public function __invoke(): Response
    {
        $contact = trim((string) config('brewless.security.contact', ''));

        abort_if($contact === '', 404);

        $lines = [];

        foreach (array_filter(array_map('trim', explode(',', $contact))) as $address) {
            // A plain address is mail; a link or an existing scheme stays as it is.
            $lines[] = 'Contact: '.(str_contains($address, ':') ? $address : 'mailto:'.$address);
        }

        $lines[] = 'Expires: '.Carbon::now('UTC')->startOfDay()->addYear()->format('Y-m-d\TH:i:s\Z');

        $policy = trim((string) config('brewless.security.policy', ''));

        if ($policy !== '') {
            $lines[] = 'Policy: '.$policy;
        }

        $lines[] = 'Preferred-Languages: '.(string) config('brewless.security.languages', 'en');
        $lines[] = 'Canonical: '.url('/.well-known/security.txt');

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
