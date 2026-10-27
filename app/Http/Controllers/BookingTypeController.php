<?php

namespace App\Http\Controllers;

use App\Models\BookingPage;
use App\Models\BookingType;
use App\Models\CalendarConnection;
use App\Support\Booking\BookingInput;
use App\Support\Booking\BookingTemplates;
use App\Support\Calendar\CalendarProviders;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Meeting types of a booking page (BRAI-158): the user's own lengths and names ("Quick question,
 * 15 min", "Workshop, 45 min"), each with hours, rules, questions and a target calendar. A template
 * type only pre-fills a new one.
 */
class BookingTypeController extends Controller
{
    public const MAX_TYPES = 20;

    public function store(Request $request, BookingPage $bookingPage): RedirectResponse
    {
        $this->authorize('update', $bookingPage);
        $validated = $request->validate([
            'template' => ['nullable', 'string', 'regex:/^[a-z_]+:\d+$/'], // "<page template>:<type index>"
            'name' => ['nullable', 'string', 'max:120'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
        ]);
        if ($bookingPage->types()->count() >= self::MAX_TYPES) {
            throw ValidationException::withMessages(['name' => 'A booking page can have at most '.self::MAX_TYPES.' meeting types.']);
        }

        $base = BookingTemplates::all()['blank']['types'][0];
        if (! empty($validated['template'])) {
            [$key, $index] = explode(':', $validated['template']);
            $base = BookingTemplates::all()[$key]['types'][(int) $index] ?? throw ValidationException::withMessages(['template' => 'Unknown template.']);
        }
        $name = $validated['name'] ?: $base['name'];
        $type = $bookingPage->types()->create(array_merge($base, [
            'name' => $name,
            'duration_minutes' => $validated['duration_minutes'] ?? $base['duration_minutes'],
            'slug' => BookingPageController::typeSlug($bookingPage, $name),
            'position' => (int) $bookingPage->types()->max('position') + 1,
            'target_connection_id' => BookingPageController::defaultTarget($bookingPage->user_id),
        ]));

        return back()->with('success', "“{$type->name}” added.");
    }

    public function update(Request $request, BookingPage $bookingPage, BookingType $bookingType): RedirectResponse
    {
        $this->authorize('update', $bookingPage);
        abort_unless($bookingType->booking_page_id === $bookingPage->id, 404);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'min:1', 'max:60', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('booking_types', 'slug')->where('booking_page_id', $bookingPage->id)->ignore($bookingType->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['required', 'integer', 'min:5', 'max:720'],
            'enabled' => ['required', 'boolean'],
            'target_connection_id' => ['nullable', 'integer'],
            'location' => ['nullable', 'array'],
            'availability' => ['required', 'array'],
            'rules' => ['nullable', 'array'],
            'questions' => ['nullable', 'array', 'max:'.BookingInput::MAX_QUESTIONS],
        ], ['slug.regex' => 'Use lower-case letters, numbers and dashes.']);

        $target = null;
        if (! empty($validated['target_connection_id'])) {
            $connection = CalendarConnection::where('user_id', $bookingPage->user_id)->where('kind', 'calendar')->find($validated['target_connection_id']);
            if (! $connection || ! CalendarProviders::has($connection->provider) || ! CalendarProviders::for($connection)->isWritable($connection)) {
                throw ValidationException::withMessages(['target_connection_id' => 'Pick one of your calendars that Brainitive can write to.']);
            }
            $target = $connection->id;
        }

        $bookingType->update([
            'name' => $validated['name'],
            'slug' => $validated['slug'],
            'description' => $validated['description'] ?? null,
            'duration_minutes' => $validated['duration_minutes'],
            'enabled' => $validated['enabled'],
            'target_connection_id' => $target,
            'location' => BookingInput::location($validated['location'] ?? []),
            'availability' => BookingInput::availability($validated['availability']),
            'rules' => BookingInput::rules($validated['rules'] ?? []),
            'questions' => BookingInput::questions($validated['questions'] ?? []),
        ]);

        return back()->with('success', 'Saved.');
    }

    public function duplicate(BookingPage $bookingPage, BookingType $bookingType): RedirectResponse
    {
        $this->authorize('update', $bookingPage);
        abort_unless($bookingType->booking_page_id === $bookingPage->id, 404);
        if ($bookingPage->types()->count() >= self::MAX_TYPES) {
            throw ValidationException::withMessages(['name' => 'A booking page can have at most '.self::MAX_TYPES.' meeting types.']);
        }

        $copy = $bookingType->replicate(['slug', 'position']);
        $copy->name = $bookingType->name.' (copy)';
        $copy->slug = BookingPageController::typeSlug($bookingPage, $copy->name);
        $copy->position = (int) $bookingPage->types()->max('position') + 1;
        $copy->save();

        return back()->with('success', "“{$copy->name}” added.");
    }

    public function reorder(Request $request, BookingPage $bookingPage): RedirectResponse
    {
        $this->authorize('update', $bookingPage);
        $validated = $request->validate(['ids' => ['required', 'array', 'max:'.self::MAX_TYPES], 'ids.*' => ['integer']]);
        $own = $bookingPage->types()->pluck('id')->all();
        foreach (array_values(array_intersect($validated['ids'], $own)) as $position => $id) {
            BookingType::whereKey($id)->update(['position' => $position]);
        }

        return back();
    }

    public function destroy(BookingPage $bookingPage, BookingType $bookingType): RedirectResponse
    {
        $this->authorize('update', $bookingPage);
        abort_unless($bookingType->booking_page_id === $bookingPage->id, 404);
        if ($bookingType->bookings()->confirmed()->where('starts_at', '>', now())->exists()) {
            throw ValidationException::withMessages(['type' => 'This meeting type has upcoming bookings. Cancel them or switch the type off instead.']);
        }
        $bookingType->delete();

        return back()->with('success', 'Meeting type deleted.');
    }
}
