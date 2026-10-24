<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One received email per helpdesk and Message-ID (v1.40.0, BRAI-79): the dedupe key of
 * InboundMail::receive() and its audit trail (result + reason), whatever the transport.
 * Pruned after 180 days (a provider redelivering after that is not a concern).
 */
class HelpdeskInboundEmail extends Model
{
    use Prunable;

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'helpdesk_id',
        'transport',
        'message_hash',
        'message_id',
        'from_email',
        'subject',
        'status',
        'reason',
        'ticket_id',
        'ticket_reply_id',
        'size',
    ];

    protected function casts(): array
    {
        return ['size' => 'integer'];
    }

    public static function hashOf(string $messageId): string
    {
        return hash('sha256', strtolower(trim($messageId, " \t\r\n<>")));
    }

    public function helpdesk(): BelongsTo
    {
        return $this->belongsTo(Helpdesk::class);
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(180));
    }
}
