<?php

namespace App\Models;

use App\Support\Booking\BookingRules;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One meeting type of a booking page (BRAI-158), e.g. "Intro call, 30 min": duration, location,
 * weekly hours + date overrides (wall-clock in the page's timezone), rules and the calendar the
 * bookings are written to.
 */
class BookingType extends Model
{
    public const LOCATIONS = ['none', 'google_meet', 'teams', 'phone', 'in_person', 'link'];

    protected $fillable = [
        'booking_page_id', 'slug', 'name', 'description', 'duration_minutes', 'location', 'availability',
        'rules', 'target_connection_id', 'questions', 'position', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'duration_minutes' => 'integer',
            'location' => 'array',
            'availability' => 'array',
            'rules' => 'array',
            'questions' => 'array',
            'position' => 'integer',
            'enabled' => 'boolean',
        ];
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(BookingPage::class, 'booking_page_id');
    }

    public function targetConnection(): BelongsTo
    {
        return $this->belongsTo(CalendarConnection::class, 'target_connection_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    public function bookingRules(): BookingRules
    {
        return BookingRules::from($this->duration_minutes, (array) $this->availability, (array) $this->rules);
    }
}
