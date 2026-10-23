<?php

namespace App\Http\Controllers;

use App\Models\LeadForm;
use App\Support\LeadForms\LeadFormDefinition;
use App\Support\LeadForms\LeadIntake;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Inbound webhook (BRAI-93): other tools (Zapier, Make, a website's own form…) push leads
 * into a form's board. `Authorization: Bearer <inbound token>`; JSON keyed by question key.
 */
class LeadFormInboundController extends Controller
{
    public function store(Request $request, string $uuid): JsonResponse
    {
        $form = LeadForm::where('uuid', $uuid)->first();
        $token = (string) ($request->bearerToken() ?? $request->header('X-Brainitive-Token', ''));

        if (! $form || ! $form->inbound_token || $token === '' || ! hash_equals($form->inbound_token, hash('sha256', $token))) {
            return response()->json(['message' => 'Invalid or missing token.'], 401);
        }

        $input = $request->isJson() ? $request->json()->all() : $request->all();
        if (! is_array($input) || count($input, COUNT_RECURSIVE) > 1000) {
            return response()->json(['message' => 'Send a JSON object with the answers.'], 422);
        }

        try {
            $result = LeadFormDefinition::validateInbound($form->definition ?? [], $input);
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Some values are invalid.', 'errors' => $e->errors()], 422);
        }

        $lead = LeadIntake::create($form, $result['answers'], 'webhook', [], $result['contact']);

        return response()->json(['id' => $lead->uuid, 'number' => $lead->number], 201);
    }
}
