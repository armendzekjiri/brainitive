<?php

namespace App\Http\Controllers;

use App\Models\ClientDeliverable;
use App\Models\ClientDeliverableComment;
use App\Models\ClientDeliverableDecision;
use App\Models\ClientPortal;
use App\Models\PortalIdentity;
use App\Support\ClientPortal\ClientPortalAccess;
use App\Support\ClientPortal\Deliverables;
use App\Support\Portal\PortalAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The client side of a project's client portal (v1.42.0, BRAI-73): `/client/{slug}…`, behind
 * `portal.identity`, plus an active membership of THIS portal (ClientPortalAccess). Clients only ever
 * get frozen deliverable content, their decisions and the comments marked for them.
 */
class ClientPortalController extends Controller
{
    public function show(Request $request, string $slug): Response
    {
        $portal = ClientPortalAccess::portal($slug);
        $identity = $this->identity($request);
        if (! ClientPortalAccess::activeMember($portal, $identity)) {
            return $this->denied($request, $portal, $identity);
        }

        $deliverables = $portal->deliverables()->current()->withCount('clientComments')
            ->orderByDesc('published_at')->orderByDesc('id')->get();

        return Inertia::render('ClientPortal/Index', [
            'portal' => $this->portalProps($portal),
            'identity' => PortalAuth::shared($request),
            'deliverables' => $deliverables->map(fn (ClientDeliverable $d) => $this->summary($d) + [
                'comments_count' => $d->client_comments_count,
            ])->values(),
        ])->toResponse($request)->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function deliverable(Request $request, string $slug, string $deliverable): Response
    {
        $portal = ClientPortalAccess::portal($slug);
        $identity = $this->identity($request);
        if (! ClientPortalAccess::activeMember($portal, $identity)) {
            return $this->denied($request, $portal, $identity);
        }
        $item = ClientPortalAccess::deliverable($portal, $deliverable);
        $item->load(['decisions', 'clientComments']);

        $versions = $portal->deliverables()->visibleToClients()->where('kind', $item->kind)
            ->where($item->kind === 'page' ? 'page_id' : 'plan_id', $item->kind === 'page' ? $item->page_id : $item->plan_id)
            ->whereNotNull($item->kind === 'page' ? 'page_id' : 'plan_id')
            ->orderByDesc('version')->get();
        $latest = $versions->first(fn (ClientDeliverable $d) => $d->isCurrent());

        return Inertia::render('ClientPortal/Deliverable', [
            'portal' => $this->portalProps($portal),
            'identity' => PortalAuth::shared($request),
            'deliverable' => $this->summary($item) + [
                'message' => $item->message,
                'html' => $item->kind === 'page' ? ($item->content['html'] ?? '') : null,
                'plan' => $item->kind === 'plan' ? ($item->content['plan'] ?? null) : null,
                'can_decide' => $item->isCurrent(),
                'latest' => ! $item->isCurrent() && $latest ? ['uuid' => $latest->uuid, 'version' => $latest->version] : null,
                'decisions' => $item->decisions->map(fn (ClientDeliverableDecision $d) => [
                    'id' => $d->id,
                    'name' => $d->identity_name,
                    'decision' => $d->decision,
                    'note' => $d->note,
                    'version' => $d->version,
                    'created_at' => $d->created_at?->toIso8601String(),
                    'is_me' => $d->portal_identity_id === $identity->id,
                ])->values(),
                'comments' => $item->clientComments->map(fn (ClientDeliverableComment $c) => [
                    'id' => $c->id,
                    'author' => $c->author_name,
                    'from_team' => $c->isFromTeam(),
                    'is_me' => $c->portal_identity_id === $identity->id,
                    'body' => $c->body,
                    'created_at' => $c->created_at?->toIso8601String(),
                ])->values(),
            ],
            'versions' => $versions->map(fn (ClientDeliverable $d) => [
                'uuid' => $d->uuid,
                'version' => $d->version,
                'status' => $d->displayStatus(),
                'decision' => $d->status,
                'published_at' => $d->published_at?->toIso8601String(),
            ])->values(),
        ])->toResponse($request)->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function decide(Request $request, string $slug, string $deliverable): RedirectResponse
    {
        [$identity, $item] = $this->memberDeliverable($request, $slug, $deliverable);
        $data = $request->validate([
            'decision' => ['required', 'in:'.ClientDeliverable::STATUS_APPROVED.','.ClientDeliverable::STATUS_CHANGES],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $record = Deliverables::decide($item, $identity, $data['decision'], isset($data['note']) ? strip_tags($data['note']) : null);

        return back()->with('success', match (true) {
            $record === null => 'Already approved.',
            $data['decision'] === ClientDeliverable::STATUS_APPROVED => 'Approved. The team has been told.',
            default => 'Change request sent to the team.',
        });
    }

    public function comment(Request $request, string $slug, string $deliverable): RedirectResponse
    {
        [$identity, $item] = $this->memberDeliverable($request, $slug, $deliverable);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);
        $body = trim(strip_tags($data['body']));
        if ($body === '') {
            return back()->withErrors(['body' => 'Write a comment first.']);
        }

        Deliverables::clientComment($item, $identity, $body);

        return back()->with('success', 'Comment sent.');
    }

    /** The deliverable as a standalone, sanitized HTML file (the portal's only "attachment"). */
    public function download(Request $request, string $slug, string $deliverable): Response
    {
        [, $item] = $this->memberDeliverable($request, $slug, $deliverable);
        abort_unless($item->kind === 'page', 404);

        $title = e($item->title);
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            ."<title>{$title} (v{$item->version})</title>"
            .'<style>body{font-family:system-ui,sans-serif;max-width:46rem;margin:2rem auto;padding:0 1rem;line-height:1.6}img{max-width:100%}</style>'
            ."</head><body><h1>{$title}</h1><p><small>Version {$item->version} · ".e($item->published_at?->toDateString() ?? '').'</small></p>'
            .($item->content['html'] ?? '').'</body></html>';
        $name = (Str::slug($item->title) ?: 'deliverable')."-v{$item->version}.html";

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Content-Security-Policy' => "default-src 'none'; img-src https: http: data:; style-src 'unsafe-inline'",
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function identity(Request $request): PortalIdentity
    {
        return $request->attributes->get('portalIdentity');
    }

    /**
     * @return array{0: PortalIdentity, 1: ClientDeliverable}
     */
    private function memberDeliverable(Request $request, string $slug, string $deliverable): array
    {
        $portal = ClientPortalAccess::portal($slug);
        $identity = $this->identity($request);
        abort_unless(ClientPortalAccess::member($portal, $identity), 404); // never invited: the portal doesn't exist for them
        abort_unless(ClientPortalAccess::activeMember($portal, $identity), 403, 'You no longer have access to this portal.');

        // superseded versions stay open for comments and downloads; decisions refuse them (Deliverables::decide)
        return [$identity, ClientPortalAccess::deliverable($portal, $deliverable)];
    }

    /**
     * Someone who was never invited gets the same 404 as an unknown portal (signing in is open to any
     * email, so a 403 would confirm that a slug exists and show its title). Former members see why.
     */
    private function denied(Request $request, ClientPortal $portal, PortalIdentity $identity): Response
    {
        $member = ClientPortalAccess::member($portal, $identity);
        abort_unless($member, 404);

        return Inertia::render('ClientPortal/Denied', [
            'portal' => ['title' => $portal->title, 'slug' => $portal->slug],
            'identity' => PortalAuth::shared($request),
            'reason' => ClientPortalAccess::denialReason($member),
        ])->toResponse($request)->setStatusCode(403)->header('X-Robots-Tag', 'noindex, nofollow');
    }

    /** @return array<string, mixed> */
    private function portalProps(ClientPortal $portal): array
    {
        return ['title' => $portal->title, 'slug' => $portal->slug, 'welcome' => $portal->welcome];
    }

    /** @return array<string, mixed> */
    private function summary(ClientDeliverable $d): array
    {
        return [
            'uuid' => $d->uuid,
            'kind' => $d->kind,
            'title' => $d->title,
            'version' => $d->version,
            'status' => $d->displayStatus(),
            'published_at' => $d->published_at?->toIso8601String(),
            'is_current' => $d->isCurrent(),
        ];
    }
}
