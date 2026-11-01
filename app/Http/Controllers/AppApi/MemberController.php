<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\AppApi\Concerns\ResolvesVisible;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\ListMembersRequest;
use App\Http\Requests\AppApi\StoreInvitationRequest;
use App\Http\Resources\AppApi\InvitationResultResource;
use App\Http\Resources\AppApi\MemberCollection;
use App\Models\Workspace;
use App\Support\Entitlements\LimitReached;
use App\Support\Workspaces\WorkspaceInvites;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * App API v1 — members (BRAI-390): workspace and project members for assignee / mention pickers
 * (whoever can open the workspace / project sees them, as on the web), and inviting people to a
 * workspace (admins, WorkspaceInvites like the web). Role changes and removals stay on the web.
 */
#[Group('Members', weight: 80)]
class MemberController extends Controller
{
    use ResolvesVisible;

    /** The workspace's members by name, with their workspace role. */
    public function workspace(ListMembersRequest $request, Workspace $workspace): MemberCollection
    {
        $this->visibleWorkspace($request, $workspace);

        return new MemberCollection($this->page($workspace->users(), $request));
    }

    /**
     * The project's members by name, with their project role.
     *
     * @throws NotFoundHttpException the project doesn't exist or the user can't open it (404)
     */
    public function project(ListMembersRequest $request, int $project): MemberCollection
    {
        $project = $this->visibleProject($request, $project);

        return new MemberCollection($this->page($project->users(), $request));
    }

    /**
     * Invite somebody by email: an existing account joins at once, anybody else gets an invitation
     * email (valid 30 days; a repeat refreshes it).
     *
     * @throws LimitReached the workspace is at its member limit (402 limit_reached)
     * @throws ValidationException already a member (422, `email`)
     */
    public function invite(StoreInvitationRequest $request, Workspace $workspace): JsonResponse
    {
        $validated = $request->validated();

        $invite = WorkspaceInvites::invite($workspace, $request->user(), $validated['email'], $validated['role'], sendInvitation: true);

        return (new InvitationResultResource($invite))->response()->setStatusCode(201);
    }

    private function page(BelongsToMany $members, ListMembersRequest $request): mixed
    {
        $search = $request->search();
        $like = $search === null ? null : '%'.addcslashes(mb_strtolower($search), '\\%_').'%';

        return $members
            ->when($like, fn ($q) => $q->where(fn ($w) => $w
                ->whereRaw('LOWER(users.name) LIKE ?', [$like])
                ->orWhereRaw('LOWER(users.email) LIKE ?', [$like])))
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->cursorPaginate($request->limit(), ['users.id', 'users.name', 'users.email', 'users.avatar'], 'cursor', $request->cursor());
    }
}
