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
        ];

        foreach ($expected as $name => $hint) {
            $this->assertTrue($tools->has($name), "{$name} is not listed");
            $this->assertSame('object', $tools[$name]['inputSchema']['type']);
            $this->assertArrayHasKey('outputSchema', $tools[$name]);
            $this->assertTrue($tools[$name]['annotations'][$hint] ?? false, "{$name} lacks {$hint}");
        }
        $this->assertContains('request_id', $tools['update-plan-tool']['inputSchema']['required']);
    }
}
