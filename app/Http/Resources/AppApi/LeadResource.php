<?php

namespace App\Http\Resources\AppApi;

use App\Http\Controllers\LeadController;
use App\Models\Lead;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lead as the web's lead board and lead sheet show it to project members (LeadController::present): contact
 * details, the answers in form order with labels, UTM / referrer and the consent proof. Never the assignee's email.
 *
 * @mixin Lead
 */
class LeadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $lead = LeadController::present($this->resource);
        $utm = is_array($lead['utm']) ? $lead['utm'] : null;

        return [
            'id' => (int) $lead['id'],
            'uuid' => (string) $lead['uuid'],
            // #number on the board
            'number' => (int) $lead['number'],
            'lead_form_id' => (int) $this->lead_form_id,
            // its pipeline column
            'column_id' => $lead['column_id'] !== null ? (int) $lead['column_id'] : null,
            'name' => $lead['name'],
            'email' => $lead['email'],
            'phone' => $lead['phone'],
            'company' => $lead['company'],
            // form | api | import …
            'source' => $lead['source'],
            // [{id, label, type, display}] in form order; type removed = a question deleted since
            'answers' => array_map(fn (array $a) => ['id' => (string) $a['id'], 'label' => (string) $a['label'], 'type' => (string) $a['type'], 'display' => (string) $a['display']], $lead['answers']),
            // utm_source, utm_medium, utm_campaign, utm_term, utm_content (as sent)
            'utm' => $utm === null ? null : array_map(fn ($v) => is_scalar($v) ? (string) $v : null, $utm),
            'referrer' => is_string($lead['referrer']) ? $lead['referrer'] : null,
            'consent_at' => $this->consent_at?->toIso8601ZuluString(),
            'consent_text' => $lead['consent_text'],
            'assignee' => $this->assignee === null ? null : PersonResource::make($this->assignee)->resolve($request),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
