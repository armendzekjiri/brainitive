<?php

namespace App\Http\Controllers;

use App\Models\Helpdesk;
use App\Support\Helpdesks\HelpdeskForm;
use Illuminate\Http\Request;
use Inertia\Inertia;

class HelpdeskWidgetController extends Controller
{
    /**
     * JSON form config for the embeddable widget (fields + branding).
     */
    public function formConfig(Helpdesk $helpdesk)
    {
        $helpdesk->load(['fields' => function ($q) {
            $q->orderBy('position');
        }]);

        $settings = $helpdesk->settings ?? [];
        $branding = [
            'brand_color' => $settings['brand_color'] ?? '#0E83DC',
            'button_text' => $settings['button_text'] ?? 'Support',
            'button_position' => $settings['button_position'] ?? 'bottom-right',
            'welcome_message' => $settings['welcome_message'] ?? 'How can we help you?',
            'logo_url' => $settings['logo_url'] ?? null,
        ];

        $defaultEditable = [];
        if (empty($settings['default_field_contact_name']['hidden'])) {
            $defaultEditable['contact_name'] = [
                'label' => $settings['default_field_contact_name']['label'] ?? 'Name',
                'is_required' => (bool) ($settings['default_field_contact_name']['is_required'] ?? false),
            ];
        }
        if (empty($settings['default_field_description']['hidden'])) {
            $defaultEditable['description'] = [
                'label' => $settings['default_field_description']['label'] ?? 'Message',
                'is_required' => (bool) ($settings['default_field_description']['is_required'] ?? false),
            ];
        }

        $attachmentsEnabled = empty($settings['default_field_attachments']['hidden']);
        // Form texts and the subject field come from the helpdesk (templates set them, BRAI-92)
        $texts = HelpdeskForm::texts($helpdesk);
        $subject = HelpdeskForm::subject($helpdesk);

        return response()->json([
            'helpdesk_uuid' => $helpdesk->uuid,
            'default_editable' => $defaultEditable,
            'attachments_enabled' => $attachmentsEnabled,
            'texts' => $texts,
            'subject' => $subject,
            'fields' => $helpdesk->fields->map(function ($f) {
                $type = $f->type;
                if (is_string($type)) {
                    $type = strtolower(trim($type));
                    $type = str_replace(' ', '_', $type);
                }

                return [
                    'id' => $f->id,
                    'name' => $f->name,
                    'label' => $f->label,
                    'type' => $type ?? 'text',
                    'options' => $f->options,
                    'is_required' => (bool) $f->is_required,
                ];
            }),
            'branding' => $branding,
        ]);
    }

    /**
     * Test page: same-origin page that contains the embed iframe.
     * Open http://localhost:8000/embed-test/{helpdesk_uuid} to verify the iframe works.
     */
    public function embedTestPage(Helpdesk $helpdesk)
    {
        $embedUrl = url('/widget/helpdesks/'.$helpdesk->uuid.'/embed');

        return response()->view('widgets.embed-test-page', [
            'embed_url' => $embedUrl,
            'helpdesk' => $helpdesk,
        ]);
    }

    /**
     * Always-visible iframe embed (form + ticket/replies, no button).
     * Embed with: <iframe src="https://your-app.com/widget/helpdesks/{uuid}/embed" width="100%" height="500" frameborder="0"></iframe>
     */
    public function embed(Request $request, Helpdesk $helpdesk)
    {
        $response = Inertia::render('Widgets/HelpdeskEmbed', [
            'helpdesk_uuid' => $helpdesk->uuid,
            'base_url' => $request->getSchemeAndHttpHost(),
            'ticket_uuid' => $request->query('ticket'),
            'token' => $request->query('token'),
        ])->toResponse($request);
        $response->headers->set('Content-Security-Policy', 'frame-ancestors *');

        return $response;
    }

    /**
     * Shareable full-page support form: /support/{public_slug}. Same form and ticket view as
     * the inline embed, with the helpdesk's name and branding around it.
     */
    public function supportPage(Request $request, string $publicSlug)
    {
        $helpdesk = Helpdesk::where('public_slug', $publicSlug)->with('project.workspace')->firstOrFail();
        $settings = $helpdesk->settings ?? [];

        return Inertia::render('Widgets/HelpdeskEmbed', [
            'helpdesk_uuid' => $helpdesk->uuid,
            'base_url' => $request->getSchemeAndHttpHost(),
            'ticket_uuid' => $request->query('ticket'),
            'token' => $request->query('token'),
            'page' => [
                'title' => $helpdesk->name,
                'organization' => $helpdesk->project?->workspace?->name,
                'logo_url' => $settings['logo_url'] ?? null,
                'brand_color' => $settings['brand_color'] ?? '#0E83DC',
            ],
        ]);
    }
}
