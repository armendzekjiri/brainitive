<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketFieldValue extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'helpdesk_field_id',
        'value',
    ];

    /**
     * Get the ticket this field value belongs to.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Get the field definition.
     */
    public function field(): BelongsTo
    {
        return $this->belongsTo(HelpdeskField::class, 'helpdesk_field_id');
    }
}