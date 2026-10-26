<?php

namespace Tests\Feature\Feedback;

use App\Models\FeedbackPortal;
use App\Models\FeedbackPost;
use App\Models\PortalIdentity;
use App\Models\User;
use App\Support\Portal\PortalAuth;
use Illuminate\Support\Str;
use Tests\Feature\Boards\InteractsWithBoards;

/**
 * A project with a board, one user per role (InteractsWithBoards) plus a project admin, an enabled
 * feedback portal, and helpers for guest identities and posts (BRAI-60).
 */
trait InteractsWithFeedback
{
    use InteractsWithBoards;

    protected User $admin;

    protected FeedbackPortal $portal;

    protected function setUpFeedback(): void
    {
        $this->setUpBoard();
        $this->admin = $this->projectMember('admin');
        $this->portal = FeedbackPortal::create([
            'project_id' => $this->project->id,
            'enabled' => true,
            'slug' => 'acme-ideas',
            'title' => 'Acme ideas',
            'intro' => 'Tell us what to build.',
        ]);
    }

    protected function identity(?string $email = null, ?string $name = 'Guest'): PortalIdentity
    {
        return PortalIdentity::create([
            'email' => $email ?? Str::random(8).'@example.com',
            'name' => $name,
            'email_verified_at' => now(),
        ]);
    }

    /** Sign a guest identity in (the session key PortalAuth reads). */
    protected function asGuest(PortalIdentity $identity): static
    {
        return $this->withSession([PortalAuth::SESSION_KEY => $identity->id]);
    }

    protected function feedbackPost(array $attributes = [], ?FeedbackPortal $portal = null): FeedbackPost
    {
        $portal ??= $this->portal;

        return $portal->posts()->create($attributes + [
            'number' => $portal->nextNumber(),
            'title' => 'Idea '.Str::random(6),
            'body' => 'Some details',
            'moderation' => 'approved',
            'status' => 'open',
            'approved_at' => now(),
        ]);
    }

    /** @param  array<int, mixed>  $extra */
    protected function teamRoute(string $name, array $extra = []): string
    {
        return route($name, [$this->workspace->slug, $this->project->slug, ...$extra]);
    }
}
