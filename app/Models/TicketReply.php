<?php

namespace App\Models;

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
    ];

    protected function casts(): array
    {
        return [
            'is_internal_note' => 'boolean',
        ];
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