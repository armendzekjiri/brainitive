<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Models\Workspace;
use App\Support\Entitlements\LimitGate;
use App\Support\Mcp\IdempotentRequest;
use App\Support\Mcp\ResourceLocator;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Create a new workspace owned by the signed-in user (they become its admin). Use it when the user wants a fresh workspace, e.g. to migrate a company into Brainitive. Returns the workspace slug for the other tools.')]
class CreateWorkspaceTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'request_id' => 'required|string|max:100',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $this->authorizeFor($user, 'create', Workspace::class);

            $run = IdempotentRequest::run($user, 'create_workspace', $v['request_id'], ['name' => $v['name'], 'description' => $v['description'] ?? null], function () use ($user, $v) {
                app(LimitGate::class)->ensureCanCreateWorkspace($user); // plan limits (v1.46.0)
                $taken = Workspace::where('owner_id', $user->id)->where('name', $v['name'])->exists();
                if ($taken) {
                    throw new ToolError("You already own a workspace named \"{$v['name']}\". Call list_workspaces to see it, or pick another name.");
                }

                $workspace = Workspace::create([
                    'name' => $v['name'],
                    'description' => $v['description'] ?? null,
                    'owner_id' => $user->id,
                ]);
                $workspace->addUser($user, 'admin');

                return ['workspace_slug' => $workspace->slug, 'location' => ResourceLocator::locate($workspace)];
            });

            return Response::structured(['created' => ! $run['replayed']] + $run['result']);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'created' => $schema->boolean(),
            'workspace_slug' => $schema->string(),
            'location' => ResourceLocator::outputSchema($schema),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The workspace name.')->required(),
            'description' => $schema->string()->description('Optional description.'),
            'request_id' => $schema->string()->description('Unique id for this write; reuse only to retry it.')->required(),
        ];
    }
}
