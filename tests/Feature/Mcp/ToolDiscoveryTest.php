<?php

namespace Tests\Feature\Mcp;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passport\Passport;
use Tests\Feature\Concerns\UsesPassportKeys;
use Tests\TestCase;

class ToolDiscoveryTest extends TestCase
{
    use RefreshDatabase;
    use UsesPassportKeys;

    public function test_new_tools_are_listed_with_schemas_and_annotations(): void
    {
        $this->usePassportKeys();
        Passport::actingAs(User::factory()->create(), ['mcp:use']);

        $tools = collect($this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertOk()->json('result.tools'))->keyBy('name');

        $expected = [
            'list-item-comments-tool' => 'readOnlyHint', 'add-item-comment-tool' => 'idempotentHint',
            'list-sprints-tool' => 'readOnlyHint', 'get-sprint-tool' => 'readOnlyHint', 'create-sprint-tool' => 'idempotentHint',
            'update-sprint-tool' => 'idempotentHint', 'move-items-to-sprint-tool' => 'idempotentHint',
            'start-sprint-tool' => 'idempotentHint', 'complete-sprint-tool' => 'idempotentHint',
            'list-plans-tool' => 'readOnlyHint', 'get-plan-tool' => 'readOnlyHint', 'update-plan-tool' => 'idempotentHint',
            // v1.47.0: project setup + bulk cards + inbox capture
            'get-project-config-tool' => 'readOnlyHint', 'create-workspace-tool' => 'idempotentHint', 'create-project-tool' => 'idempotentHint',
            'update-project-tool' => 'idempotentHint', 'add-project-member-tool' => 'idempotentHint', 'create-board-tool' => 'idempotentHint',
            'update-board-tool' => 'idempotentHint', 'create-board-column-tool' => 'idempotentHint', 'update-board-column-tool' => 'idempotentHint',
            'delete-board-column-tool' => 'idempotentHint', 'reorder-board-columns-tool' => 'idempotentHint', 'create-label-tool' => 'idempotentHint',
            'update-label-tool' => 'idempotentHint', 'delete-label-tool' => 'idempotentHint', 'create-item-type-tool' => 'idempotentHint',
            'update-item-type-tool' => 'idempotentHint', 'create-priority-tool' => 'idempotentHint', 'update-priority-tool' => 'idempotentHint',
            'create-item-field-tool' => 'idempotentHint', 'update-item-field-tool' => 'idempotentHint', 'create-task-list-tool' => 'idempotentHint',
            'create-board-items-tool' => 'idempotentHint', 'capture-to-inbox-tool' => 'idempotentHint',
            // BRAI-432: related content, referenced by, linked issues
            'list-content-links-tool' => 'readOnlyHint', 'link-related-content-tool' => 'idempotentHint',
            'unlink-related-content-tool' => 'idempotentHint', 'link-issues-tool' => 'idempotentHint', 'unlink-issues-tool' => 'idempotentHint',
        ];

        foreach ($expected as $name => $hint) {
            $this->assertTrue($tools->has($name), "{$name} is not listed");
            $this->assertSame('object', $tools[$name]['inputSchema']['type']);
            $this->assertArrayHasKey('outputSchema', $tools[$name]);
            $this->assertTrue($tools[$name]['annotations'][$hint] ?? false, "{$name} lacks {$hint}");
        }
        $this->assertContains('request_id', $tools['update-plan-tool']['inputSchema']['required']);
        foreach (['link-related-content-tool', 'unlink-related-content-tool', 'link-issues-tool', 'unlink-issues-tool'] as $name) {
            $this->assertContains('request_id', $tools[$name]['inputSchema']['required']);
        }
    }
}
