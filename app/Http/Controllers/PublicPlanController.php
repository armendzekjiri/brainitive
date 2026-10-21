<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Support\Plans\PlanDocument;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only plan behind a share link ("publish to web"); no login needed.
 */
class PublicPlanController extends Controller
{
    public function show(string $token): Response
    {
        $plan = Plan::where('share_token', $token)->with('project:id,name,color')->firstOrFail();

        return Inertia::render('Plans/Public', [
            'plan' => [
                'name' => $plan->name,
                'description' => $plan->description,
                'project' => $plan->project?->name,
                'color' => $plan->project?->color,
                'data' => $plan->data ?? Plan::emptyData(),
                'settings' => PlanDocument::normalizeSettings($plan->settings),
                'updated_at' => $plan->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
