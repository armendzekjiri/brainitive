<?php

namespace App\Support\Helpdesks\Mail;

use RuntimeException;

/**
 * A mailbox connection problem whose message is safe to show to helpdesk admins (no passwords,
 * no stack traces). Stored as `helpdesk_mailboxes.last_error`.
 */
class MailboxException extends RuntimeException
{
    /** A short, safe message from any connection error. */
    public static function from(\Throwable $e): self
    {
        if ($e instanceof self) {
            return $e;
        }

        // the innermost message is the useful one (webklex wraps socket errors)
        $messages = [];
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            $text = trim(preg_replace('/\s+/', ' ', $cause->getMessage()) ?? '');
            if ($text !== '') {
                $messages[] = $text;
            }
        }
        $text = implode(': ', array_unique($messages)) ?: 'Connection failed.';

        return new self(mb_substr($text, 0, 300), 0, $e);
    }
}
