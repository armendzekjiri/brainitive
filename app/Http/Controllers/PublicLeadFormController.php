<?php

namespace App\Http\Controllers;

use App\Models\LeadForm;
use App\Models\LeadFormEvent;
use App\Support\LeadForms\LeadFormDefinition;
use App\Support\LeadForms\LeadFormOptions;
use App\Support\LeadForms\LeadIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public side of a lead form (no login): the page at /f/{public_slug}, the iframe embed,
 * submissions (throttled, honeypot) and anonymous analytics events. Drafts are 404.
 */
class PublicLeadFormController extends Controller
{
    public function show(Request $request, string $publicSlug): Response
    {
        return $this->render($request, $this->published($publicSlug), false);
    }

    public function embed(Request $request, string $publicSlug): Response
    {
        $response = $this->render($request, $this->published($publicSlug), true);
        $response->headers->set('Content-Security-Policy', 'frame-ancestors *');

        return $response;
    }

    public function submit(Request $request, string $publicSlug): JsonResponse
    {
        $form = $this->published($publicSlug);

        $validated = $request->validate([
            'answers' => 'present|array|max:700',
            'session_id' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'utm' => 'nullable|array:source,medium,campaign,term,content',
            'utm.*' => 'nullable|string|max:200',
            'referrer' => 'nullable|string|max:500',
            'website' => 'nullable', // honeypot: people never see it, bots fill it in
        ]);

        $settings = LeadFormOptions::settings($form->settings);
        $done = [
            'ok' => true,
            'after_submit' => $settings['after_submit'],
            'redirect_url' => $settings['after_submit'] === 'redirect' ? $settings['redirect_url'] : null,
            'thank_you_title' => $settings['thank_you_title'],
            'thank_you_message' => $settings['thank_you_message'],
        ];

        if (filled($validated['website'] ?? null)) {
            return response()->json($done); // looks like success, nothing is stored
        }

        $answers = LeadFormDefinition::validateAnswers($form->definition ?? [], $validated['answers']);

        $referrer = $validated['referrer'] ?? null;
        LeadIntake::create($form, $answers, 'form', [
            'utm' => array_filter($validated['utm'] ?? [], fn ($v) => filled($v)) ?: null,
            'referrer' => $referrer && preg_match('#^https?://#i', $referrer) ? $referrer : null,
        ]);

        if (! empty($validated['session_id'])) {
            $this->record($form, $validated['session_id'], 'submit');
        }

        return response()->json($done);
    }

    public function event(Request $request, string $publicSlug): HttpResponse
    {
        $form = $this->published($publicSlug);

        $validated = $request->validate([
            'session_id' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_-]+$/'],
            'type' => ['required', 'in:view,start,step'],
            'step' => ['nullable', 'integer', 'min:0', 'max:'.(LeadFormDefinition::MAX_STEPS - 1)],
        ]);

        $this->record($form, $validated['session_id'], $validated['type'], $validated['type'] === 'step' ? (int) ($validated['step'] ?? 0) : null);

        return response()->noContent();
    }

    private function published(string $publicSlug): LeadForm
    {
        // a form of a deleted project / workspace is offline too
        $form = LeadForm::where('public_slug', $publicSlug)->whereHas('project.workspace')->first();
        abort_unless($form && $form->isPublished(), 404);

        return $form;
    }

    /** Once per visit session (and per step), so reloads don't inflate the numbers. */
    private function record(LeadForm $form, string $sessionId, string $type, ?int $step = null): void
    {
        $exists = LeadFormEvent::where('lead_form_id', $form->id)->where('session_id', $sessionId)
            ->where('type', $type)->when($type === 'step', fn ($q) => $q->where('step', $step))->exists();

        if (! $exists) {
            LeadFormEvent::create(['lead_form_id' => $form->id, 'session_id' => $sessionId, 'type' => $type, 'step' => $step]);
        }
    }

    private function render(Request $request, LeadForm $form, bool $embed): Response
    {
        $form->loadMissing('project.workspace');

        return Inertia::render('LeadForms/Public', [
            'form' => [
                'name' => $form->name,
                'public_slug' => $form->public_slug,
                'definition' => $form->definition,
                'theme' => LeadFormOptions::theme($form->theme),
                'settings' => LeadFormOptions::publicSettings(LeadFormOptions::settings($form->settings)),
                'organization' => $form->project?->workspace?->name,
            ],
            'embed' => $embed,
            'submitUrl' => route('lead-forms.submit', $form->public_slug),
            'eventsUrl' => route('lead-forms.events', $form->public_slug),
        ])->toResponse($request);
    }
}
