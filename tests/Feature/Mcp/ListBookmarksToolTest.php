<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\BrainitiveServer;
use App\Mcp\Tools\ListBookmarksTool;
use App\Models\User;
use App\Support\Bookmarks\Bookmarks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Boards\InteractsWithBoards;
use Tests\TestCase;

/**
 * Codex review (low): list_bookmarks loaded only id / name / slug / description, so the visibility check saw no
 * creator and no visibility and hid groups the user may see (their own, public ones, everything for an admin).
 */
class ListBookmarksToolTest extends TestCase
{
    use InteractsWithBoards;
    use RefreshDatabase;

    /** @return list<string> */
    private function names(User $user): array
    {
        $response = BrainitiveServer::actingAs($user)->tool(ListBookmarksTool::class, ['workspace' => $this->workspace->slug])->assertOk();
        $names = [];
        $response->assertStructuredContent(function ($json) use (&$names) {
            $names = collect($json->toArray()['bookmarks'])->pluck('name')->sort()->values()->all();

            return $json->etc();
        });

        return $names;
    }

    public function test_lists_the_groups_the_user_may_see(): void
    {
        $this->setUpBoard();
        $admin = User::findOrFail($this->workspace->owner_id);
        Bookmarks::createGroup($this->workspace, $this->editor, ['name' => 'Mine', 'visibility' => 'private']);
        Bookmarks::createGroup($this->workspace, $admin, ['name' => 'Team', 'visibility' => 'public']);
        Bookmarks::createGroup($this->workspace, $admin, ['name' => 'Admin only', 'visibility' => 'private']);

        $this->assertSame(['Mine', 'Team'], $this->names($this->editor));
        $this->assertSame(['Team'], $this->names($this->viewer));
        $this->assertSame(['Admin only', 'Mine', 'Team'], $this->names($admin));
    }
}
