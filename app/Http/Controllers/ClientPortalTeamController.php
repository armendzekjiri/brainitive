<?php

namespace App\Http\Controllers;

use App\Models\ClientDeliverable;
use App\Models\ClientDeliverableComment;
use App\Models\ClientDeliverableDecision;
use App\Models\ClientPortal;
use App\Models\ClientPortalMember;
use App\Models\Page;
use App\Models\Plan;
use App\Models\PortalIdentity;
use App\Models\Project;
use App\Models\Workspace;
use App\Notifications\ClientPortalGuestNotification;
use App\Support\ClientPortal\Deliverables;
use App\Support\Portal\PortalAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The team side of a project's client portal (v1.42.0, BRAI-73):
 * `/workspaces/{w}/projects/{p}/client-portal`. Project admins manage settings and clients; admins and
 * editors publish deliverables and answer (explicit "Reply to client" vs internal note).
 * Abilities: ClientPortalPolicy. Contract: docs/ai/tasks/release-v1.42.0.md.
 */
class ClientPortalTeamController extends Controller
{
    public function index(Request $request, Workspace $workspace, Project $project): Response
    {
        $this->authorize('viewAny', [ClientPortal::class, $project]);
        $user = $request->user();
        $portal = $this->portal($project);
        $canManage = $user->can('manage', [ClientPortal::class, $project]);
        $canPublish = $user->can('publish', [ClientPortal::class, $project]);

        $deliverables = $portal
            ? $portal->deliverables()->with('publisher:id,name')->withCount(['decisions', 'comments'])
                ->orderByRaw('superseded_at is not null')->orderByRaw('withdrawn_at is not null')
                ->orderByDesc('published_at')->orderByDesc('id')->limit(300)->get()
            : collect();

        return Inertia::render('Projects/ClientPortal', [
            'workspace' => $workspace,
            'project' => $project,
            'portal' => $portal ? [
                'title' => $portal->title,
                'slug' => $portal->slug,
                'welcome' => $portal->welcome,
                'enabled' => $portal->enabled,
                'url' => $portal->publicUrl(),
            ] : null,
            'suggested' => ['title' => $project->name, 'slug' => ClientPortal::suggestSlug($project->name)],
            'members' => $portal ? $portal->members()->with(['identity', 'inviter:id,name'])->orderByDesc('invited_at')->get()
                ->map(fn (ClientPortalMember $m) => [
                    'id' => $m->id,
                    'name' => $m->identity?->displayName(),
                    'email' => $canManage ? $m->identity?->email : null, // clients' addresses: project admins only
                    'state' => $m->state(),
                    'invited_by' => $m->inviter?->name,
                    'invited_at' => $m->invited_at?->toIso8601String(),
                    'expires_at' => $m->expires_at?->toIso8601String(),
                    'revoked_at' => $m->revoked_at?->toIso8601String(),
                    'last_seen_at' => $m->last_seen_at?->toIso8601String(),
                ])->values() : [],
            'deliverables' => $deliverables->map(fn (ClientDeliverable $d) => $this->summary($d))->values(),
            'candidates' => $canPublish ? [
                'pages' => Page::where('project_id', $project->id)->visibleTo($user)
                    ->where('type', 'page')->where('status', 'published')->where('current_version', '>', 0)
                    ->orderBy('name')->limit(500)->get()
                    ->reject(fn (Page $p) => $p->is_whiteboard)
                    ->map(fn (Page $p) => ['id' => $p->uuid, 'name' => $p->name, 'version' => $p->current_version])->values(),
                'plans' => $project->plans()->orderBy('name')->limit(200)->get(['id', 'name'])
                    ->map(fn (Plan $p) => ['id' => (string) $p->id, 'name' => $p->name])->values(),
            ] : ['pages' => [], 'plans' => []],
            // the billing client's email (workspace admins only, like billing itself)
            'billingClient' => $canManage && $project->client_id && Gate::allows('manageBilling', $workspace)
                ? $project->client()->first(['id', 'name', 'email'])?->only(['name', 'email']) : null,
            'canManage' => $canManage,
            'canPublish' => $canPublish,
        ]);
    }

    public function update(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('manage', [ClientPortal::class, $project]);
        $portal = $this->portal($project);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'title' => ['required', 'string', 'max:120'],
            'slug' => ['required', 'string', 'min:3', 'max:60', 'regex:/^'.ClientPortal::SLUG_PATTERN.'$/', Rule::unique('client_portals', 'slug')->ignore($portal?->id)],
            'welcome' => ['nullable', 'string', 'max:2000'],
        ], ['slug.regex' => 'Use lower-case letters, numbers and dashes.']);

        $title = trim(strip_tags($data['title']));
        if ($title === '') {
            throw ValidationException::withMessages(['title' => 'Give the portal a title.']);
        }
        $attributes = [
            'enabled' => $data['enabled'],
            'title' => $title,
            'slug' => $data['slug'],
            'welcome' => isset($data['welcome']) && trim($data['welcome']) !== '' ? trim(strip_tags($data['welcome'])) : null,
        ];

        if ($portal) {
            $portal->update($attributes);
        } else {
            // a double-submitted first save must not hit the unique project_id
            ClientPortal::createOrFirst(['project_id' => $project->id], $attributes + ['created_by' => $request->user()->id])->update($attributes);
        }

        return back()->with('success', $data['enabled'] ? 'Client portal saved.' : 'Client portal saved (off).');
    }

    /** Invite (or re-invite) a client by email: membership + invitation mail + one-time sign-in link. */
    public function invite(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('manage', [ClientPortal::class, $project]);
        $portal = $this->portal($project);
        if (! $portal?->enabled) {
            throw ValidationException::withMessages(['email' => 'Turn the client portal on first.']);
        }

        $data = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['nullable', 'string', 'max:120'],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);
        $name = isset($data['name']) ? Str::limit(trim(strip_tags($data['name'])), 120, '') : '';

        $member = DB::transaction(function () use ($portal, $data, $name, $request) {
            $identity = PortalIdentity::createOrFirst(['email' => PortalIdentity::normalizeEmail($data['email'])]);
            if ($name !== '' && ! $identity->name) {
                $identity->forceFill(['name' => $name])->save();
            }

            $member = ClientPortalMember::firstOrNew(['client_portal_id' => $portal->id, 'portal_identity_id' => $identity->id]);
            $member->fill([
                'invited_by' => $request->user()->id,
                'invited_at' => now(),
                'expires_at' => isset($data['expires_at']) ? Carbon::parse($data['expires_at'])->endOfDay() : null,
                'revoked_at' => null,
                'revoked_by' => null,
            ])->save();

            return $member->setRelation('identity', $identity);
        });

        // the friendly invitation first, then the one-time sign-in link (the newest mail)
        // at most 3 invitation mails per portal + address an hour (re-inviting still restores access)
        $email = $member->identity->email;
        RateLimiter::attempt('client-portal-invite:'.$portal->id.':'.sha1($email), 3, fn () => $member->identity->notify(
            new ClientPortalGuestNotification(ClientPortalGuestNotification::INVITED, $portal, null, $request->user()->name),
        ), 3600);
        PortalAuth::sendLink($member->identity->email, $name !== '' ? $name : null, $portal->publicPath());

        return back()->with('success', "Invitation sent to {$member->identity->email}.");
    }

    public function revoke(Request $request, Workspace $workspace, Project $project, int $member): RedirectResponse
    {
        $this->authorize('manage', [ClientPortal::class, $project]);
        $row = $this->portalOrFail($project)->members()->findOrFail($member);

        if ($row->revoked_at === null) {
            $row->forceFill(['revoked_at' => now(), 'revoked_by' => $request->user()->id])->save();
        }

        return back()->with('success', 'Access revoked.');
    }

    public function publish(Request $request, Workspace $workspace, Project $project): RedirectResponse
    {
        $this->authorize('publish', [ClientPortal::class, $project]);
        $portal = $this->portalOrFail($project);

        $data = $request->validate([
            'kind' => ['required', 'in:page,plan'],
            'id' => ['required', 'string', 'max:64'],
            'message' => ['nullable', 'string', 'max:2000'],
        ]);
        $message = isset($data['message']) ? strip_tags($data['message']) : null;
        $user = $request->user();

        if ($data['kind'] === 'page') {
            $page = Str::isUuid($data['id']) ? Page::where('project_id', $project->id)->where('uuid', $data['id'])->first() : null;
            if (! $page || ! $user->can('view', $page)) {
                throw ValidationException::withMessages(['id' => 'Pick a published doc of this project.']);
            }
            [$deliverable, $created] = Deliverables::publishPage($portal, $page, $user, $message);
        } else {
            $plan = ctype_digit($data['id']) ? Plan::where('project_id', $project->id)->whereKey((int) $data['id'])->first() : null;
            if (! $plan || ! $user->can('view', $plan)) {
                throw ValidationException::withMessages(['id' => 'Pick a plan of this project.']);
            }
            [$deliverable, $created] = Deliverables::publishPlan($portal, $plan, $user, $message);
        }

        return redirect()->route('client-portal.manage.deliverables.show', [$workspace->slug, $project->slug, $deliverable->id])
            ->with('success', $created ? "Version {$deliverable->version} is now in the client portal." : 'This version is already in the client portal.');
    }

    public function showDeliverable(Request $request, Workspace $workspace, Project $project, int $deliverable): Response
    {
        $this->authorize('viewAny', [ClientPortal::class, $project]);
        $portal = $this->portalOrFail($project);
        $item = $portal->deliverables()->with(['publisher:id,name', 'decisions', 'comments'])->findOrFail($deliverable);
        $canManage = $request->user()->can('manage', [ClientPortal::class, $project]);

        $key = $item->kind === 'page' ? 'page_id' : 'plan_id';
        $versions = $item->{$key}
            ? $portal->deliverables()->where('kind', $item->kind)->where($key, $item->{$key})->orderByDesc('version')->orderByDesc('id')->get()
            : collect([$item]);

        return Inertia::render('Projects/ClientPortalDeliverable', [
            'workspace' => $workspace,
            'project' => $project,
            'portal' => ['title' => $portal->title, 'slug' => $portal->slug, 'enabled' => $portal->enabled, 'url' => $portal->publicUrl()],
            'deliverable' => $this->summary($item) + [
                'message' => $item->message,
                'html' => $item->kind === 'page' ? ($item->content['html'] ?? '') : null,
                'plan' => $item->kind === 'plan' ? ($item->content['plan'] ?? null) : null,
                'client_url' => $item->withdrawn_at === null ? route('client-portal.deliverable', [$portal->slug, $item->uuid]) : null,
                'decisions' => $item->decisions->map(fn (ClientDeliverableDecision $d) => [
                    'id' => $d->id,
                    'name' => $d->identity_name,
                    'email' => $canManage ? $d->identity_email : null,
                    'decision' => $d->decision,
                    'note' => $d->note,
                    'version' => $d->version,
                    'created_at' => $d->created_at?->toIso8601String(),
                ])->values(),
                'comments' => $item->comments->map(fn (ClientDeliverableComment $c) => [
                    'id' => $c->id,
                    'author' => $c->author_name,
                    'from_team' => $c->isFromTeam(),
                    'visibility' => $c->visibility,
                    'body' => $c->body,
                    'created_at' => $c->created_at?->toIso8601String(),
                ])->values(),
            ],
            'versions' => $versions->map(fn (ClientDeliverable $d) => [
                'id' => $d->id,
                'version' => $d->version,
                'status' => $d->displayStatus(),
                'decision' => $d->status,
                'published_at' => $d->published_at?->toIso8601String(),
            ])->values(),
            'canPublish' => $request->user()->can('publish', [ClientPortal::class, $project]),
            'canManage' => $canManage,
        ]);
    }

    public function withdraw(Request $request, Workspace $workspace, Project $project, int $deliverable): RedirectResponse
    {
        $this->authorize('publish', [ClientPortal::class, $project]);
        Deliverables::withdraw($this->portalOrFail($project)->deliverables()->findOrFail($deliverable));

        return redirect()->route('client-portal.manage.index', [$workspace->slug, $project->slug])->with('success', 'Removed from the client portal.');
    }

    public function comment(Request $request, Workspace $workspace, Project $project, int $deliverable): RedirectResponse
    {
        $this->authorize('publish', [ClientPortal::class, $project]);
        $item = $this->portalOrFail($project)->deliverables()->findOrFail($deliverable);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'visibility' => ['required', 'in:'.ClientDeliverableComment::VISIBILITY_CLIENT.','.ClientDeliverableComment::VISIBILITY_INTERNAL],
        ]);
        $body = trim(strip_tags($data['body']));
        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Write something first.']);
        }
        $toClient = $data['visibility'] === ClientDeliverableComment::VISIBILITY_CLIENT;
        if ($toClient && $item->withdrawn_at !== null) {
            throw ValidationException::withMessages(['body' => 'This deliverable was removed from the portal; add an internal note instead.']);
        }

        Deliverables::teamComment($item, $request->user(), $body, $toClient);

        return back()->with('success', $toClient ? 'Reply sent to the client.' : 'Internal note added.');
    }

    private function portal(Project $project): ?ClientPortal
    {
        return ClientPortal::where('project_id', $project->id)->first();
    }

    private function portalOrFail(Project $project): ClientPortal
    {
        $portal = $this->portal($project);
        abort_unless($portal, 404);

        return $portal;
    }

    /** @return array<string, mixed> */
    private function summary(ClientDeliverable $d): array
    {
        $last = $d->relationLoaded('decisions') ? $d->decisions->last() : null;

        return [
            'id' => $d->id,
            'uuid' => $d->uuid,
            'kind' => $d->kind,
            'title' => $d->title,
            'version' => $d->version,
            'status' => $d->displayStatus(),
            'decision' => $d->status,
            'published_at' => $d->published_at?->toIso8601String(),
            'published_by' => $d->publisher?->name,
            'decisions_count' => $d->decisions_count ?? ($d->relationLoaded('decisions') ? $d->decisions->count() : null),
            'comments_count' => $d->comments_count ?? ($d->relationLoaded('comments') ? $d->comments->count() : null),
            'last_decision_at' => $last?->created_at?->toIso8601String(),
        ];
    }
}
