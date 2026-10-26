<?php

namespace Tests\Feature\ClientPortal;

use App\Models\ClientDeliverable;
use App\Models\ClientPortal;
use App\Models\ClientPortalMember;
use App\Models\Page;
use App\Models\PortalIdentity;
use App\Support\ClientPortal\Deliverables;
use App\Support\Portal\PortalAuth;
use Tests\Feature\Pages\InteractsWithPages;

/** A project with an enabled client portal, plus helpers for guests (v1.42.0, BRAI-73). */
trait InteractsWithClientPortal
{
    use InteractsWithPages;

    protected ClientPortal $portal;

    protected function setUpClientPortal(): void
    {
        $this->setUpPages();
        $this->portal = ClientPortal::create([
            'project_id' => $this->project->id,
            'slug' => 'acme-site',
            'title' => 'Acme website',
            'welcome' => 'Hello Acme',
            'enabled' => true,
            'created_by' => $this->admin->id,
        ]);
    }

    protected function guest(string $email = 'client@acme.test', string $name = 'Clara Client'): PortalIdentity
    {
        return PortalIdentity::create(['email' => $email, 'name' => $name, 'email_verified_at' => now()]);
    }

    /** An invited (active) client of $portal. */
    protected function client(?ClientPortal $portal = null, string $email = 'client@acme.test', array $member = []): PortalIdentity
    {
        $portal ??= $this->portal;
        $identity = PortalIdentity::findByEmail($email) ?? $this->guest($email);
        ClientPortalMember::create($member + [
            'client_portal_id' => $portal->id,
            'portal_identity_id' => $identity->id,
            'invited_by' => $this->admin->id,
            'invited_at' => now(),
        ]);

        return $identity;
    }

    /** Sign the guest in (what consuming the emailed link does). */
    protected function as(PortalIdentity $identity): static
    {
        return $this->withSession([PortalAuth::SESSION_KEY => $identity->id]);
    }

    protected function sharedPage(string $html = '<p>Version one text</p>', string $name = 'Homepage copy'): array
    {
        $page = $this->publishedPage(['name' => $name, 'content' => $this->html($html)]);
        [$deliverable] = Deliverables::publishPage($this->portal, $page, $this->author);

        return [$page, $deliverable];
    }

    /** Publish changes to a published page: a new page version. */
    protected function newVersion(Page $page, string $html): void
    {
        $page->saveDraft($this->author, ['content' => $this->html($html)]);
        $page->fresh()->publishChanges($this->author);
    }

    protected function deliverableUrl(ClientDeliverable $d, string $suffix = ''): string
    {
        return '/client/'.$d->portal->slug.'/d/'.$d->uuid.$suffix;
    }
}
