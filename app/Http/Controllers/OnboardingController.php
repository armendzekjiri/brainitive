<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OnboardingController extends Controller
{
    public function markWelcomeSeen(Request $request): JsonResponse
    {
        $user = $request->user();
        $state = $user->onboarding_state ?? [];
        $state['welcome_seen'] = true;
        $state['welcome_seen_at'] = now()->toIso8601String();
        if (isset($request->focus) && in_array($request->focus, ['project_mgmt', 'docs', 'support', 'all'], true)) {
            $state['focus'] = $request->focus;
        }
        $user->onboarding_state = $state;
        $user->save();

        return response()->json(['ok' => true]);
    }

    public function dismissChecklist(Request $request): JsonResponse
    {
        $user = $request->user();
        $state = $user->onboarding_state ?? [];
        $state['checklist_dismissed'] = true;
        $state['checklist_dismissed_at'] = now()->toIso8601String();
        $user->onboarding_state = $state;
        $user->save();

        return response()->json(['ok' => true]);
    }
}
