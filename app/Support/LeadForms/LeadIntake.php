<?php

namespace App\Support\LeadForms;

use App\Jobs\DeliverLeadWebhook;
use App\Models\Lead;
use App\Models\LeadForm;
use App\Models\User;
use App\Notifications\LeadConfirmationNotification;
use App\Notifications\NewLeadNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Turns validated answers into a lead card (public form and inbound webhook alike): first
 * column of the form's board, next number, contact fields copied from mapped answers. Then the
 * team is notified, the lead gets the confirmation email and the outgoing webhook is queued.
 */
class LeadIntake
{
    /**
     * @param  array<string, mixed>  $answers  clean answers, field id => value
     * @param  array<string, mixed>  $meta  utm / referrer
     * @param  array<string, string>  $contact  name / email / … when no question is mapped to them
     */
    public static function create(LeadForm $form, array $answers, string $source, array $meta = [], array $contact = []): Lead
    {
        $definition = $form->definition ?? ['steps' => []];
        // Labels at submit time, so answers to questions removed later stay readable
        $labels = [];
        foreach (LeadFormDefinition::fields($definition) as $field) {
            if (array_key_exists($field['id'], $answers)) {
                $labels[$field['id']] = $field['label'];
            }
        }

        $contact = array_merge($contact, array_filter(LeadFormDefinition::contact($definition, $answers), fn ($v) => $v !== null));

        $lead = DB::transaction(function () use ($form, $answers, $source, $meta, $contact, $labels) {
            $column = $form->columns()->first()
                ?? $form->columns()->create(['name' => 'New', 'slug' => 'new', 'position' => 0]);

            return $form->leads()->create([
                'column_id' => $column->id,
                'number' => $form->takeLeadNumber(),
                ...array_intersect_key($contact, array_flip(LeadFormDefinition::MAPS)),
                'answers' => $answers,
                'source' => $source,
                'meta' => array_filter($meta + ['labels' => $labels], fn ($v) => $v !== null && $v !== [] && $v !== ''),
                'position' => 0, // newest first: same position, ordered by id desc
            ]);
        });

        self::afterCreate($form, $lead);

        return $lead;
    }

    private static function afterCreate(LeadForm $form, Lead $lead): void
    {
        $settings = LeadFormOptions::settings($form->settings);
        $project = $form->project;

        $recipients = User::whereIn('id', $settings['notify_user_ids'])->get()
            ->filter(fn (User $user) => $user->hasProjectAccess($project));
        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new NewLeadNotification($lead));
        }

        if ($settings['confirmation_email'] && $lead->email) {
            Notification::route('mail', $lead->email)->notify(new LeadConfirmationNotification($lead));
        }

        if ($settings['webhook_url']) {
            DeliverLeadWebhook::dispatch($lead->id);
        }
    }

    /**
     * The JSON a lead is shared as (outgoing webhook, API): answers keyed by field key.
     */
    public static function payload(Lead $lead): array
    {
        $form = $lead->form;
        $project = $form->project;

        return [
            'event' => 'lead.created',
            'form' => ['id' => $form->uuid, 'name' => $form->name],
            'lead' => [
                'id' => $lead->uuid,
                'number' => $lead->number,
                'name' => $lead->name,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'company' => $lead->company,
                'source' => $lead->source,
                'created_at' => $lead->created_at?->toIso8601String(),
                'answers' => LeadFormDefinition::readable($form->definition ?? [], $lead->answers ?? []),
                'utm' => $lead->meta['utm'] ?? null,
                'referrer' => $lead->meta['referrer'] ?? null,
                'url' => route('lead-forms.show', [$project->workspace->slug, $project->slug, $form->slug]).'?lead='.$lead->uuid,
            ],
        ];
    }
}
