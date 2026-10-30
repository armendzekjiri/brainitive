<?php

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\InteractsWithBrainitive;
use App\Support\Mcp\ToolError;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[Description('Add a workspace member to a project by email with a role (admin, editor or viewer), or change the role of someone already in it. Only people who are already in the workspace can be added — invitations are sent from Brainitive.')]
class AddProjectMemberTool extends Tool
{
    use InteractsWithBrainitive;

    public function handle(Request $request): Response|ResponseFactory
    {
        $v = $request->validate([
            'workspace' => 'required|string',
            'project' => 'required|string',
            'email' => 'required|email',
            'role' => 'required|in:admin,editor,viewer',
        ]);
        $user = $request->user();

        return $this->guard(function () use ($user, $v) {
            $project = $this->projectForUser($user, $v['workspace'], $v['project']);
            $this->authorizeFor($user, 'manageMembers', $project);

            $member = $project->workspace->users()->where('email', $v['email'])->first();
            if (! $member) {
                throw new ToolError("No workspace member with the email \"{$v['email']}\". Invite them to the workspace in Brainitive first (Workspace → Members).");
            }

            if ($project->hasUser($member)) {
                $project->updateUserRole($member, $v['role']);
                $added = false;
            } else {
                $project->addUser($member, $v['role'], $user);
                $added = true;
            }

            return Response::structured(['added' => $added, 'name' => $member->name, 'email' => $member->email, 'role' => $v['role']]);
        });
    }

    /** @return array<string, JsonSchema> */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'added' => $schema->boolean()->description('false when they were already a member (role updated).'),
            'name' => $schema->string(),
            'email' => $schema->string(),
            'role' => $schema->string(),
        ];
    }

    /** @return array<string, JsonSchema> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('The workspace slug.')->required(),
            'project' => $schema->string()->description('The project slug.')->required(),
            'email' => $schema->string()->description('The member\'s email (must already be in the workspace).')->required(),
            'role' => $schema->string()->description('admin, editor or viewer.')->required(),
        ];
    }
}
