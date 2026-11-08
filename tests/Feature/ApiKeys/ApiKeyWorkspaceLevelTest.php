<?php

namespace Tests\Feature\ApiKeys;

use App\Models\ApiKey;
use App\Models\Booking;
use App\Models\BookingPage;
use App\Models\Bookmark;
use App\Models\Snippet;
use App\Models\SnippetCollection;
use App\Models\User;
use App\Support\ApiKeys\ApiKeyAreas;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\ApiKeys\Concerns\UsesApiKeys;
use Tests\Feature\AppApi\Concerns\ForgetsGuardsBetweenRequests;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Developer API (v1.52.0, Codex review): workspace-level content — booking pages and bookings (also as calendar
 * events and in Today), snippets, bookmarks, workspace members, workspace insights —
 * is only for keys over whole workspaces. A key limited to projects or boards, even with every area + write +
 * delete, gets 403 or empty lists, and no guest names / emails / titles leak. One rule, applied centrally
 * (ApiKeyAccess::scopeModel through the ScopedToApiKey global scope, workspace-wide locations in AuthorizeApiKey).
 */
class ApiKeyWorkspaceLevelTest extends TestCase
{
    use ForgetsGuardsBetweenRequests;
    use InteractsWithBoards;
    use RefreshDatabase;
    use UsesApiKeys;

    private const LEAKS = ['Gina Guest', 'gina@client.test', 'Secret pricing', 'Hidden booking page', 'Deploy snippets', 'rm -rf secret', 'Team links'];

    private User $owner;

    private BookingPage $page;

    private Booking $booking;

    private SnippetCollection $collection;

    private Snippet $snippet;

    private Bookmark $bookmarks;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoard();
        // the workspace admin: may see everything of the workspace as a user
        $this->owner = User::findOrFail($this->workspace->owner_id);

        $this->page = BookingPage::create(['user_id' => $this->owner->id, 'workspace_id' => $this->workspace->id, 'public_slug' => 'hidden',
            'title' => 'Hidden booking page', 'timezone' => 'UTC', 'settings' => ['consent' => 'off']]);
        $type = $this->page->types()->create(['slug' => 'intro', 'name' => 'Intro call', 'duration_minutes' => 30,
            'availability' => ['weekly' => [], 'overrides' => []], 'location' => ['kind' => 'none', 'value' => null], 'questions' => []]);
        $start = CarbonImmutable::now('UTC')->addHour()->startOfHour();
        $this->booking = Booking::create(['booking_type_id' => $type->id, 'user_id' => $this->owner->id, 'starts_at' => $start, 'ends_at' => $start->addMinutes(30),
            'guest_name' => 'Gina Guest', 'guest_email' => 'gina@client.test', 'guest_timezone' => 'UTC', 'answers' => ['topic' => 'Secret pricing'],
            'status' => Booking::CONFIRMED, 'manage_token_hash' => hash('sha256', 'x')]);

        $this->collection = SnippetCollection::create(['workspace_id' => $this->workspace->id, 'name' => 'Deploy snippets', 'created_by' => $this->owner->id]);
        $this->snippet = Snippet::create(['collection_id' => $this->collection->id, 'title' => 'Cleanup', 'content' => 'rm -rf secret', 'language' => 'bash', 'created_by' => $this->owner->id]);
        $this->bookmarks = Bookmark::create(['workspace_id' => $this->workspace->id, 'name' => 'Team links', 'created_by' => $this->owner->id, 'visibility' => 'public']);
    }

    /** @return array<string, string> */
    private function narrowKeys(): array
    {
        $every = array_fill_keys(ApiKeyAreas::keys(), 'write');

        return [
            'projects' => $this->apiKey($this->owner, $every, ApiKey::SCOPE_PROJECTS, [$this->project->id], canDelete: true),
            'boards' => $this->apiKey($this->owner, $every, ApiKey::SCOPE_BOARDS, [$this->board->id], canDelete: true),
        ];
    }

    private function assertNothingLeaks($response, string $what): void
    {
        foreach (self::LEAKS as $leak) {
            $this->assertStringNotContainsString($leak, (string) $response->getContent(), "{$what} leaked “{$leak}”");
        }
    }

    public function test_narrow_keys_get_no_workspace_level_content(): void
    {
        $slug = $this->workspace->slug;
        $day = now()->toDateString();
        $to = now()->addDay()->toDateString();

        foreach ($this->narrowKeys() as $scope => $key) {
            $h = $this->keyHeaders($key);
            $refused = [
                ['GET', "/workspaces/{$slug}/snippet-collections"], ['POST', "/workspaces/{$slug}/snippet-collections"],
                ['GET', "/snippet-collections/{$this->collection->id}"], ['GET', "/snippet-collections/{$this->collection->id}/snippets"],
                ['PATCH', "/snippet-collections/{$this->collection->id}"], ['DELETE', "/snippet-collections/{$this->collection->id}"],
                ['GET', "/snippets/{$this->snippet->id}"], ['DELETE', "/snippets/{$this->snippet->id}"],
                ['GET', "/workspaces/{$slug}/bookmarks"], ['POST', "/workspaces/{$slug}/bookmarks"],
                ['GET', "/bookmarks/{$this->bookmarks->id}"], ['GET', "/bookmarks/{$this->bookmarks->id}/items"], ['DELETE', "/bookmarks/{$this->bookmarks->id}"],
                ['GET', "/workspaces/{$slug}/members"], ['GET', "/workspaces/{$slug}/insights/overview"],
                ['GET', "/bookings/{$this->booking->uuid}"], ['POST', "/bookings/{$this->booking->uuid}/cancel"],
            ];
            foreach ($refused as [$method, $uri]) {
                $response = $this->json($method, '/api/v1'.$uri, [], $h);
                $this->assertContains($response->status(), [403, 404, 422], "{$scope}: {$method} {$uri} answered {$response->status()}");
                $this->assertNothingLeaks($response, "{$scope}: {$method} {$uri}");
            }

            foreach (['/booking-pages', '/bookings', '/me/today', "/calendar/events?from={$day}&to={$to}", '/work-logs', "/me/timesheet?workspace={$slug}"] as $uri) {
                $response = $this->getJson('/api/v1'.$uri, $h);
                $this->assertSame(200, $response->status(), "{$scope}: GET {$uri}: ".$response->getContent());
                $this->assertNothingLeaks($response, "{$scope}: GET {$uri}");
            }
            $this->assertSame([], $this->getJson('/api/v1/booking-pages', $h)->json('data'));
            $this->assertSame([], $this->getJson('/api/v1/bookings', $h)->json('data'));
        }

        $this->assertSame(Booking::CONFIRMED, $this->booking->fresh()->status);
        $this->assertModelExists($this->collection);
        $this->assertModelExists($this->bookmarks);
    }

    public function test_keys_over_the_whole_workspace_still_reach_it(): void
    {
        $every = array_fill_keys(ApiKeyAreas::keys(), 'read');
        foreach ([[ApiKey::SCOPE_ALL, []], [ApiKey::SCOPE_WORKSPACES, [$this->workspace->id]]] as [$scope, $ids]) {
            $h = $this->keyHeaders($this->apiKey($this->owner, $every, $scope, $ids));
            $this->assertSame(['Hidden booking page'], array_column($this->getJson('/api/v1/booking-pages', $h)->assertOk()->json('data'), 'title'));
            $this->getJson('/api/v1/bookings', $h)->assertOk()->assertJsonPath('data.0.guest_name', 'Gina Guest');
            $this->getJson("/api/v1/snippets/{$this->snippet->id}", $h)->assertOk();
            $this->getJson("/api/v1/bookmarks/{$this->bookmarks->id}", $h)->assertOk();
        }
    }
}
