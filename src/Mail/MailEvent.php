<?php

declare(strict_types=1);

namespace Brewless\Laravel\Mail;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * What became of a mail, as Scaleway Transactional Email reported it:
 * `email_delivered`, `email_dropped`, `email_deferred`, `email_spam`,
 * `email_mailbox_not_found`, `email_blocklisted` or `email_queued`. Listen
 * for it to mark an address as bouncing, for instance:
 *
 *     Event::listen(MailEvent::class, function (MailEvent $event) {
 *         if ($event->bounced()) { ... $event->to ... }
 *     });
 */
final class MailEvent
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload  everything Scaleway sent, headers of the mail included
     */
    public function __construct(
        public readonly string $type,
        public readonly string $emailId,
        public readonly string $to,
        public readonly string $from,
        public readonly ?string $error,
        public readonly ?string $at,
        public readonly array $payload,
    ) {}

    /**
     * Whether the address did not take the mail and will not: no mailbox, or
     * dropped for good, or on Scaleway's blocklist.
     */
    public function bounced(): bool
    {
        return in_array($this->type, ['email_mailbox_not_found', 'email_dropped', 'email_blocklisted'], true);
    }

    /**
     * Whether the receiver marked the mail as spam.
     */
    public function complained(): bool
    {
        return $this->type === 'email_spam';
    }
}
