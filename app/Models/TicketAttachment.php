<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'ticket_id',
        'ticket_reply_id',
        'filename',
        'original_filename',
        'path',
        'mime_type',
        'size',
        'uploaded_by_contact',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'uploaded_by_contact' => 'boolean',
        ];
    }

    /**
     * Get the ticket this attachment belongs to.
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /**
     * Get the reply this attachment belongs to.
     */
    public function reply(): BelongsTo
    {
        return $this->belongsTo(TicketReply::class, 'ticket_reply_id');
    }

    /**
     * Get the formatted file size.
     */
    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->size;
        $units = ['B', 'KB', 'MB', 'GB'];

        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    /**
     * Check if the attachment is an image.
     */
    public function isImage(): bool
    {
        return str_starts_with($this->mime_type ?? '', 'image/');
    }
}
