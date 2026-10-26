<?php

namespace App\Support\ClientPortal;

use App\Models\ClientDeliverable;
use App\Models\ClientDeliverableComment;
use App\Models\ClientDeliverableDecision;
use App\Models\ClientPortal;
use App\Models\Page;
use App\Models\Plan;
use App\Models\PortalIdentity;
use App\Models\Project;
use App\Models\User;
use App\Notifications\ClientCommentedNotification;
use App\Notifications\ClientDecisionNotification;
use App\Notifications\ClientPortalGuestNotification;
use App\Support\Mcp\PageContentConverter;
use App\Support\Plans\PlanDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Every write to client deliverables (v1.42.0, BRAI-73): publish (freeze + supersede), withdraw,
 * client decisions, comments. Rules:
 *
 * - a deliverable is frozen at publish; a newer publish of the same page / plan supersedes the
 *   current one (its decision is kept as history, the new one is "awaiting") — approvals never carry over;
 * - publishing the version that is already current is a no-op (same page version / identical snapshot);
 * - decisions only on the current version, under a row lock (a publish racing a decision can't attach
 *   the decision to the wrong version); approving what is already approved records nothing;
 * - notifications are sent after commit: the team (publisher + project admins) for client actions,
 *   active clients (mail only) for new deliverables and team replies.
 */
final class Deliverables
{
    /** @return array{0: ClientDeliverable, 1: bool} the deliverable and whether it is new */
    public static function publishPage(ClientPortal $portal, Page $page, User $user, ?string $message = null): array
    {
        $version = $page->versions()->first();
        if (! $version || ! $page->isPublished() || ! $page->isPage() || $page->is_whiteboard) {
            throw ValidationException::withMessages(['id' => 'Only published docs can be shared with clients.']);
        }

        return self::publish($portal, $user, [
            'kind' => 'page',
            'page_id' => $page->id,
            'page_version_id' => $version->id,
            'title' => $version->name ?: $page->name,
            'content' => ['html' => DeliverableHtml::clean(PageContentConverter::pageContentToHtml($version->content))],
        ], fn (?ClientDeliverable $current) => $current?->page_version_id === $version->id, fn () => $version->version, $message);
    }

    /** @return array{0: ClientDeliverable, 1: bool} */
    public static function publishPlan(ClientPortal $portal, Plan $plan, User $user, ?string $message = null): array
    {
        $snapshot = self::planSnapshot($plan);

        return self::publish($portal, $user, [
            'kind' => 'plan',
            'plan_id' => $plan->id,
            'title' => $plan->name,
            'content' => ['plan' => $snapshot],
        ], fn (?ClientDeliverable $current) => $current !== null && $current->content == ['plan' => $snapshot],
            fn () => (int) $portal->deliverables()->where('kind', 'plan')->where('plan_id', $plan->id)->max('version') + 1, $message);
    }

    /**
     * The plan as clients see it, frozen: hidden columns removed (not just unshown, like the public
     * plan page), and no links to board issues (internal ids).
     *
     * @return array{description: string|null, data: array<string, mixed>, settings: array<string, mixed>}
     */
    public static function planSnapshot(Plan $plan): array
    {
        $settings = PlanDocument::normalizeSettings($plan->settings);
        $data = $plan->data ?? Plan::emptyData();
        $columns = $settings['columns'];
        $hidden = array_keys(array_filter(['who' => ! $columns['who'], 'notes' => ! $columns['notes'], 'status' => ! $columns['status']]));
        $drop = array_flip([...$hidden, 'itemId']);
        $data['rows'] = array_values(array_map(function (array $row) use ($drop, $columns) {
            $row = array_diff_key($row, $drop);
            if (! $columns['progress']) {
                $row['bars'] = array_map(fn (array $bar) => array_diff_key($bar, ['progress' => true]), $row['bars'] ?? []);
            }

            return $row;
        }, $data['rows'] ?? []));
        $data['dependencies'] = array_values($data['dependencies'] ?? []);

        return ['description' => $plan->description, 'data' => $data, 'settings' => $settings];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  callable(ClientDeliverable|null): bool  $isSame
     * @param  callable(): int  $nextVersion
     * @return array{0: ClientDeliverable, 1: bool}
     */
    private static function publish(ClientPortal $portal, User $user, array $attributes, callable $isSame, callable $nextVersion, ?string $message): array
    {
        $message = $message !== null && trim($message) !== '' ? trim($message) : null;

        [$deliverable, $created] = DB::transaction(function () use ($portal, $user, $attributes, $isSame, $nextVersion, $message) {
            // one publish at a time per portal: "the current deliverable of this page / plan" stays unique
            ClientPortal::whereKey($portal->id)->lockForUpdate()->first();

            $key = $attributes['kind'] === 'page' ? 'page_id' : 'plan_id';
            $current = $portal->deliverables()->current()->where('kind', $attributes['kind'])->where($key, $attributes[$key])->latest('id')->first();
            if ($isSame($current)) {
                return [$current, false];
            }

            $deliverable = $portal->deliverables()->create($attributes + [
                'version' => $nextVersion(),
                'message' => $message,
                'status' => ClientDeliverable::STATUS_AWAITING,
                'published_by' => $user->id,
                'published_at' => now(),
            ]);
            $current?->forceFill(['superseded_at' => now(), 'superseded_by_id' => $deliverable->id])->save();

            return [$deliverable, true];
        });

        if ($created) {
            $clients = self::activeClients($portal);
            DB::afterCommit(fn () => Notification::send($clients, new ClientPortalGuestNotification(
                ClientPortalGuestNotification::PUBLISHED, $portal, $deliverable, $user->name, $message,
            )));
        }

        return [$deliverable, $created];
    }

    /** Take a deliverable out of the portal (clients get 404). History stays for the team. */
    public static function withdraw(ClientDeliverable $deliverable): void
    {
        if ($deliverable->withdrawn_at === null) {
            $deliverable->forceFill(['withdrawn_at' => now()])->save();
        }
    }

    /**
     * A client's decision on the current version. Returns null when nothing changed (approving an
     * approved deliverable). Refuses superseded / withdrawn deliverables.
     */
    public static function decide(ClientDeliverable $deliverable, PortalIdentity $identity, string $decision, ?string $note): ?ClientDeliverableDecision
    {
        $note = $note !== null && trim($note) !== '' ? trim($note) : null;

        $record = DB::transaction(function () use ($deliverable, $identity, $decision, $note) {
            $locked = ClientDeliverable::whereKey($deliverable->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isCurrent()) {
                throw ValidationException::withMessages(['decision' => 'A newer version was published. Review the latest version instead.']);
            }
            if ($decision === ClientDeliverable::STATUS_APPROVED && $locked->status === ClientDeliverable::STATUS_APPROVED) {
                return null; // idempotent
            }

            $record = $locked->decisions()->create([
                'portal_identity_id' => $identity->id,
                'identity_name' => $identity->displayName(),
                'identity_email' => $identity->email,
                'decision' => $decision,
                'note' => $note,
                'version' => $locked->version,
                'created_at' => now(),
            ]);
            $locked->forceFill(['status' => $decision])->save();
            $deliverable->setRawAttributes($locked->getAttributes(), true);

            return $record;
        });

        if ($record) {
            $recipients = self::team($deliverable, $identity);
            DB::afterCommit(fn () => Notification::send($recipients, new ClientDecisionNotification($deliverable, $record)));
        }

        return $record;
    }

    public static function clientComment(ClientDeliverable $deliverable, PortalIdentity $identity, string $body): ClientDeliverableComment
    {
        $comment = $deliverable->comments()->create([
            'portal_identity_id' => $identity->id,
            'author_name' => $identity->displayName(),
            'visibility' => ClientDeliverableComment::VISIBILITY_CLIENT,
            'body' => trim($body),
        ]);

        $recipients = self::team($deliverable, $identity);
        DB::afterCommit(fn () => Notification::send($recipients, new ClientCommentedNotification($deliverable, $comment)));

        return $comment;
    }

    /** A team comment: a reply the clients see (and get by mail), or an internal note. */
    public static function teamComment(ClientDeliverable $deliverable, User $user, string $body, bool $toClient): ClientDeliverableComment
    {
        $comment = $deliverable->comments()->create([
            'user_id' => $user->id,
            'author_name' => $user->name,
            'visibility' => $toClient ? ClientDeliverableComment::VISIBILITY_CLIENT : ClientDeliverableComment::VISIBILITY_INTERNAL,
            'body' => trim($body),
        ]);

        if ($toClient && $deliverable->withdrawn_at === null) {
            $portal = $deliverable->portal;
            $clients = self::activeClients($portal);
            DB::afterCommit(fn () => Notification::send($clients, new ClientPortalGuestNotification(
                ClientPortalGuestNotification::REPLIED, $portal, $deliverable, $user->name, $comment->body,
            )));
        }

        return $comment;
    }

    /** @return Collection<int, PortalIdentity> the identities of the portal's active clients */
    public static function activeClients(ClientPortal $portal): Collection
    {
        if (! $portal->enabled) {
            return collect();
        }

        return PortalIdentity::whereIn('id', $portal->members()->active()->select('portal_identity_id'))->get();
    }

    /**
     * Who on the team hears about client actions: the publisher and the project admins (workspace
     * owner / admins, project admins), only people who can still open the project, never the person
     * who acted (a team member who is also an invited client).
     *
     * @return Collection<int, User>
     */
    public static function team(ClientDeliverable $deliverable, ?PortalIdentity $actor = null): Collection
    {
        /** @var Project|null $project */
        $project = $deliverable->portal?->project;
        $workspace = $project?->workspace;
        if (! $project || ! $workspace) {
            return collect(); // trashed project / workspace: nobody to tell
        }

        $ids = collect([$deliverable->published_by, $workspace->owner_id])
            ->merge($project->admins()->pluck('users.id'))
            ->merge($workspace->admins()->pluck('users.id'))
            ->filter()->unique();

        return User::whereIn('id', $ids)->get()
            ->reject(fn (User $user) => $actor && ($user->id === $actor->user_id || PortalIdentity::normalizeEmail($user->email) === $actor->email))
            ->filter(fn (User $user) => $user->hasProjectAccess($project))
            ->values();
    }
}
