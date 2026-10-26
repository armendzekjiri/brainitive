<?php

namespace App\Models;

use App\Support\Helpdesks\Sla\TicketSla;
use App\Support\Live\LiveThreads;
use App\Support\SafeHtml;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketReply extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'user_id',
        'ticket_contact_id',
        'body',
        'is_internal_note',
        // sent by email (BRAI-79): stable Message-ID + delivery state
        'email_message_id',
        'email_status',
        'email_error',
        'email_sent_at',
    ];

    /**
     * Rich text that can come from the public helpdesk: sanitized when written and when
     * read (rows saved before sanitizing existed).
     */
    protected function body(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => SafeHtml::clean($value),
            set: fn (?string $value) => SafeHtml::clean($value),
        );
    }

    protected function casts(): array
    {
        return [
            'is_internal_note' => 'boolean',
            'email_sent_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // SLA (BRAI-125): the first public agent reply is the ticket's first response, whatever the path
        static::created(fn (TicketReply $reply) => TicketSla::recordReply($reply));
        // Live replies (BRAI-151): open ticket panels refetch (ids only)
        static::created(fn (TicketReply $reply) => LiveThreads::ticketReply($reply, 'created'));
        static::updated(function (TicketReply $reply) {
            if ($reply->wasChanged(['body', 'is_internal_note', 'email_status'])) {
                LiveThreads::ticketReply($reply, 'updated');
            }
        });
        static::deleted(fn (TicketReply $reply) => LiveThreads::ticketReply($reply, 'deleted'));
    }

    /**
     * Get the ticket this reply belongs to.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Get the agent who wrote this reply.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the contact who wrote this reply.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(TicketContact::class, 'ticket_contact_id');
    }

    /**
     * Get attachments for this reply.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    /**
     * Check if this reply is from a contact.
     */
    public function isFromContact(): bool
    {
        return $this->ticket_contact_id !== null;
    }

    /**
     * Check if this reply is from an agent.
     */
    public function isFromAgent(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Get the author's name.
     */
    public function getAuthorNameAttribute(): string
    {
        if ($this->isFromAgent()) {
            return $this->user?->name ?? 'Agent';
        }

        return $this->contact?->name ?? $this->contact?->email ?? 'Customer';
    }

    /**
     * Get the author's email.
     */
    public function getAuthorEmailAttribute(): ?string
    {
        if ($this->isFromAgent()) {
            return $this->user?->email;
        }

        return $this->contact?->email;
    }
}
