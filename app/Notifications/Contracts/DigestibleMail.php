<?php

namespace App\Notifications\Contracts;

use App\Support\Notifications\DigestEntry;

/**
 * A notification whose email may wait for the notification email digest (BRAI-429). Only types in
 * MailDigest::TYPES are batched; RespectsPreferences::channelsFor() then routes `mail` through
 * DigestMailChannel, which stores digestEntry() instead of sending toMail().
 */
interface DigestibleMail
{
    /** The digest line for $notifiable, or null to send the normal email at once. */
    public function digestEntry(object $notifiable): ?DigestEntry;
}
