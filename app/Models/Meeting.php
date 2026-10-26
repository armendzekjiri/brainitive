<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * An online meeting (calendar event, usually with a Google Meet / Teams link) scheduled from an
 * issue or a document (BRAI-147). The event itself lives in the organiser's calendar; this row
 * links it to its subject. `attendees` = [{email, name, user_id}] (no secrets).
 */
class Meeting extends Model
{
    /** subject kind (request / UI) → model class (stored in subject_type) */
    public const SUBJECTS = ['item' => Item::class, 'page' => Page::class];

    public const CONFERENCE_LABELS = ['google_meet' => 'Google Meet', 'teams' => 'Microsoft Teams', 'other' => 'Video call'];

    /** Most attendees one meeting may invite. */
    public const MAX_ATTENDEES = 50;

    protected $fillable = [
        'project_id',
        'subject_type',
        'subject_id',
        'calendar_connection_id',
        'provider',
        'external_event_id',
        'conference_provider',
        'join_url',
        'event_url',
        'title',
        'starts_at',
        'ends_at',
        'timezone',
        'attendees',
        'created_by',
        'meeting_notes_page_id',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'attendees' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** The issue or document (null once it was deleted). */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class, 'calendar_connection_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function notesPage(): BelongsTo
    {
        return $this->belongsTo(Page::class, 'meeting_notes_page_id');
    }

    /** 'item' | 'page' */
    public function subjectKind(): string
    {
        return (string) array_search($this->subject_type, self::SUBJECTS, true);
    }

    public function conferenceLabel(): ?string
    {
        return $this->conference_provider ? (self::CONFERENCE_LABELS[$this->conference_provider] ?? 'Video call') : null;
    }

    /** "Tue, Nov 3 · 14:00 CET" in the meeting's zone — for history entries and notes. */
    public function whenLabel(): string
    {
        return $this->starts_at->copy()->setTimezone($this->timezone)->format('D, M j · H:i T');
    }
}
