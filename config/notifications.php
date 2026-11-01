<?php

/*
 * Notification delivery (BRAI-429). See App\Support\Notifications\MailDigest.
 */
return [
    // Activity emails (assignments, mentions, comments, status changes, page updates, sprints) are
    // collected for this many seconds from the first one and sent as one email per person and
    // workspace. 0 = off: every notification email goes out on its own, at once. The bell, web push
    // and live updates are never delayed. Needs a queue worker (database / redis); with
    // QUEUE_CONNECTION=sync every email is sent at once.
    'email_digest_seconds' => (int) env('NOTIFICATION_EMAIL_DIGEST_SECONDS', 60),
];
