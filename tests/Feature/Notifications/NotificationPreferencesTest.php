<?php

namespace Tests\Feature\Notifications;

use App\Models\InboxNotification;
use App\Models\Item;
use App\Models\Lead;
use App\Models\Ticket;
use App\Models\TicketContact;
use App\Models\User;
use App\Notifications\CalendarReminderNotification;
use App\Notifications\ItemAssignedNotification;
use App\Notifications\MentionNotification;
use App\Notifications\NewLeadNotification;
use App\Notifications\TicketCreatedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

/** Profile → Notifications: per-category channels, and every catalog class honours them (BRAI-102). */
class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_preferences_merges_into_the_stored_choice(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->patchJson(route('notifications.preferences'), [
            'preferences' => ['mentions' => ['email' => true], 'comments' => ['in_app' => false, 'push' => true]],
        ])->assertOk()->assertJsonPath('categories.0.key', 'mentions');

        $this->actingAs($user)->patchJson(route('notifications.preferences'), [
            'preferences' => ['mentions' => ['push' => false]],
        ])->assertOk();

        $stored = $user->fresh()->notification_preferences;
        $this->assertSame(['in_app' => true, 'push' => false, 'email' => true], $stored['mentions']);
        $this->assertSame(['in_app' => false, 'push' => true, 'email' => false], $stored['comments']);
        $this->assertArrayNotHasKey('helpdesk', $stored);
    }

    public function test_an_inertia_save_goes_back(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->from(route('profile.edit'))
            ->patch(route('notifications.preferences'), ['preferences' => ['sprints' => ['in_app' => false]]])
            ->assertRedirect(route('profile.edit'));

        $this->assertFalse($user->fresh()->notification_preferences['sprints']['in_app']);
    }

    public function test_validation_rejects_unknown_categories_channels_and_values(): void
    {
        $user = User::factory()->create();
        $save = fn (array $body) => $this->actingAs($user)->patchJson(route('notifications.preferences'), $body);

        $save([])->assertUnprocessable()->assertJsonValidationErrors('preferences');
        $save(['preferences' => ['bogus' => ['in_app' => true]]])->assertUnprocessable()->assertJsonValidationErrors('preferences');
        $save(['preferences' => ['mentions' => ['sms' => true]]])->assertUnprocessable()->assertJsonValidationErrors('preferences.mentions');
        $save(['preferences' => ['mentions' => ['push' => 'loud']]])->assertUnprocessable()->assertJsonValidationErrors('preferences.mentions.push');
        $save(['preferences' => ['mentions' => 'off']])->assertUnprocessable();

        $this->assertNull($user->fresh()->notification_preferences);
    }

    public function test_the_profile_page_lists_every_category(): void
    {
        $user = User::factory()->create(['notification_preferences' => ['leads' => ['email' => false]]]);

        $this->withoutVite()->actingAs($user)->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('notificationCategories', 10)
                ->where('notificationCategories.0.key', 'mentions')
                ->where('notificationCategories.8.key', 'leads')
                ->where('notificationCategories.8.channels', ['in_app' => true, 'push' => true, 'email' => false]));
    }

    public function test_defaults_keep_todays_channels(): void
    {
        $user = User::factory()->create();
        $actor = User::factory()->create();

        $mention = new MentionNotification('item', 'Spec', '/x', $actor);
        $this->assertSame(['database', WebPushChannel::class], $mention->via($user));

        $calendar = new CalendarReminderNotification('Standup', 'in 10 minutes');
        $this->assertSame([WebPushChannel::class], $calendar->via($user));
    }

    public function test_via_follows_the_users_category_choice(): void
    {
        $actor = User::factory()->create();
        $item = Item::factory()->create();
        $user = User::factory()->create(['notification_preferences' => ['assignments' => ['in_app' => false, 'push' => false, 'email' => true]]]);

        $this->assertSame(['mail'], (new ItemAssignedNotification($item, $actor))->via($user));

        $user->update(['notification_preferences' => ['reminders' => ['push' => false]]]);
        $this->assertSame([], (new CalendarReminderNotification('Standup', 'now'))->via($user->fresh()));
    }

    public function test_ticket_contacts_still_get_mail_only(): void
    {
        $contact = new TicketContact(['email' => 'c@example.com']);
        $ticket = new Ticket;

        $this->assertSame(['mail'], (new TicketCreatedNotification($ticket, $contact))->via($contact));
    }

    public function test_leads_keep_mail_by_default(): void
    {
        $user = User::factory()->create();
        $lead = new Lead;

        $this->assertSame(['database', WebPushChannel::class, 'mail'], (new NewLeadNotification($lead))->via($user));
    }

    public function test_turning_in_app_off_stops_the_notification_in_the_inbox(): void
    {
        $actor = User::factory()->create();
        $user = User::factory()->create(['notification_preferences' => ['mentions' => ['in_app' => false, 'push' => false]]]);

        $user->notify(new MentionNotification('item', 'Spec', '/x', $actor));
        $this->assertSame(0, $user->notifications()->count());

        $user->update(['notification_preferences' => ['mentions' => ['in_app' => true, 'push' => false]]]);
        $user->fresh()->notify(new MentionNotification('item', 'Spec', '/x', $actor));
        $this->assertSame(1, $user->notifications()->count());
    }

    public function test_old_notifications_are_pruned(): void
    {
        $user = User::factory()->create();
        $row = fn (array $attributes) => $user->notifications()->create($attributes + [
            'id' => (string) Str::uuid(), 'type' => 'x', 'data' => ['type' => 'mentioned'],
        ])->id;

        $fresh = $row([]);
        $recentlyArchived = $row(['archived_at' => now()->subDays(30), 'read_at' => now()]);
        $oldArchived = $row(['archived_at' => now()->subDays(91), 'read_at' => now()]);
        $oldUnarchived = $row(['created_at' => now()->subDays(181)]);
        $agingUnarchived = $row(['created_at' => now()->subDays(120)]);

        $this->artisan('model:prune', ['--model' => [InboxNotification::class]])->assertSuccessful();

        $left = InboxNotification::pluck('id')->all();
        $this->assertEqualsCanonicalizing([$fresh, $recentlyArchived, $agingUnarchived], $left);
        $this->assertNotContains($oldArchived, $left);
        $this->assertNotContains($oldUnarchived, $left);
    }
}
