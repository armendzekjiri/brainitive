<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The IMAP mailbox connected to a helpdesk (v1.40.0, BRAI-79), plus how replies go out (the app's
 * mailer from that address, or the mailbox's own SMTP server). Passwords are encrypted at rest,
 * hidden from serialization and never sent to the browser (HelpdeskMailboxController::payload()).
 */
class HelpdeskMailbox extends Model
{
    public const ENCRYPTIONS = ['ssl', 'starttls', 'none'];

    public const SMTP_MODES = ['app', 'smtp'];

    protected $fillable = [
        'helpdesk_id',
        'enabled',
        'email_address',
        'from_name',
        'imap_host',
        'imap_port',
        'imap_encryption',
        'imap_username',
        'imap_password',
        'imap_folder',
        'processed_folder',
        'smtp_mode',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'last_checked_at',
        'last_success_at',
        'last_error',
        'consecutive_failures',
        'created_by',
    ];

    protected $hidden = ['imap_password', 'smtp_password'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'imap_port' => 'integer',
            'smtp_port' => 'integer',
            'imap_password' => 'encrypted',
            'smtp_password' => 'encrypted',
            'last_checked_at' => 'datetime',
            'last_success_at' => 'datetime',
            'consecutive_failures' => 'integer',
        ];
    }

    public function helpdesk(): BelongsTo
    {
        return $this->belongsTo(Helpdesk::class);
    }

    /** SMTP credentials: its own, or the IMAP ones when left empty (most providers use the same). */
    public function smtpUsername(): string
    {
        return (string) ($this->smtp_username ?: $this->imap_username);
    }

    public function smtpPassword(): string
    {
        return (string) ($this->smtp_password ?: $this->imap_password);
    }

    /** The address in From / Reply-To, lower-cased. */
    public function address(): string
    {
        return strtolower(trim($this->email_address));
    }
}
