<?php

namespace App\Models;

use App\Support\SafeHtml;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Ticket extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'helpdesk_id',
        'column_id',
        'ticket_contact_id',
        'uuid',
        'key',
        'subject',
        'description',
        'assigned_to',
        'priority_id',
        'position',
        'completed_at',
        'metadata',
    ];

    /**
     * Rich text that can come from the public helpdesk: sanitized when written and when
     * read (rows saved before sanitizing existed).
     */
    protected function description(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => SafeHtml::clean($value),
            set: fn (?string $value) => SafeHtml::clean($value),
        );
    }

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (Ticket $ticket) {
            if (empty($ticket->uuid)) {
                $ticket->uuid = (string) Str::uuid();
            }
        });

        // Auto-watch: add assigned agent to watchers after creation
        static::created(function (Ticket $ticket) {
            if ($ticket->assigned_to) {
                $ticket->watchers()->syncWithoutDetaching([$ticket->assigned_to]);
            }
        });

        // Auto-watch: add assignee when ticket is assigned/updated
        static::updated(function (Ticket $ticket) {
            if ($ticket->wasChanged('assigned_to') && $ticket->assigned_to) {
                $ticket->watchers()->syncWithoutDetaching([$ticket->assigned_to]);
            }
        });
    }

    /**
     * Get the helpdesk this ticket belongs to.
     */
    public function helpdesk(): BelongsTo
    {
        return $this->belongsTo(Helpdesk::class);
    }

    /**
     * Get the column this ticket is in.
     */
    public function column(): BelongsTo
    {
        return $this->belongsTo(HelpdeskColumn::class, 'column_id');
    }

    /**
     * The secret in the contact's ticket link and the widget / portal requests. Derived
     * from this ticket only (HMAC with the app key), so it opens exactly one ticket, never
     * changes, and can't be obtained by submitting another ticket with the same email.
     */
    public function publicAccessToken(): string
    {
        return hash_hmac('sha256', 'ticket-access|'.$this->uuid, (string) config('app.key'));
    }

    public function verifyPublicAccessToken(?string $token): bool
    {
        return is_string($token) && $token !== '' && hash_equals($this->publicAccessToken(), $token);
    }

    /** Portal link for the contact. */
    public function publicUrl(): string
    {
        return route('helpdesks.tickets.show-public', $this->uuid).'?token='.urlencode($this->publicAccessToken());
    }

    /**
     * Get the contact who submitted this ticket.
     */
    public function contact(): BelongsTo
    {
        return $this->belongsTo(TicketContact::class, 'ticket_contact_id');
    }

    /**
     * Get the assigned agent.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the priority.
     */
    public function priority(): BelongsTo
    {
        return $this->belongsTo(ItemPriority::class, 'priority_id');
    }

    /**
     * Get the users watching this ticket.
     */
    public function watchers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ticket_watchers')
            ->withPivot('created_at');
    }

    /**
     * Add a user to the watch list (idempotent).
     */
    public function addWatcher(User $user): void
    {
        $this->watchers()->syncWithoutDetaching([$user->id]);
    }

    /**
     * Remove a user from the watch list.
     */
    public function removeWatcher(User $user): void
    {
        $this->watchers()->detach($user->id);
    }

    /**
     * Get custom field values for this ticket.
     */
    public function fieldValues(): HasMany
    {
        return $this->hasMany(TicketFieldValue::class);
    }

    /**
     * Get replies for this ticket.
     */
    public function replies(): HasMany
    {
        return $this->hasMany(TicketReply::class)->orderBy('created_at');
    }

    /**
     * Get attachments for this ticket.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    /**
     * Get activity logs for this ticket.
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ActivityLog::class, 'loggable_id')
            ->where('loggable_type', self::class)
            ->orderByDesc('created_at');
    }

    /**
     * Move ticket to a different column.
     */
    public function moveToColumn(HelpdeskColumn $column, int $position = 0): void
    {
        $wasInDoneColumn = $this->column?->is_done_column ?? false;
        $isNowInDoneColumn = $column->is_done_column;

        $this->update([
            'column_id' => $column->id,
            'position' => $position,
            'completed_at' => $isNowInDoneColumn ? now() : null,
        ]);

        // If moving out of done column, clear completed_at
        if ($wasInDoneColumn && ! $isNowInDoneColumn) {
            $this->update(['completed_at' => null]);
        }
    }

    /**
     * Assign this ticket to an agent.
     */
    public function assignTo(?User $user): void
    {
        $this->update(['assigned_to' => $user?->id]);
    }

    /**
     * Check if the ticket is completed.
     */
    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }

    /**
     * Get the project through the helpdesk.
     */
    public function getProjectAttribute(): ?Project
    {
        return $this->helpdesk?->project;
    }

    /**
     * Get the workspace through the helpdesk and project.
     */
    public function getWorkspaceAttribute(): ?Workspace
    {
        return $this->helpdesk?->project?->workspace;
    }
}
