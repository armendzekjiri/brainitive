<?php

namespace App\Http\Controllers;

use App\Models\Plan;
use App\Support\Legal\PublicOperator;
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
        $plan = Plan::where('share_token', $token)->with('project:id,name,color,workspace_id', 'project.workspace')->firstOrFail();
        $settings = PlanDocument::normalizeSettings($plan->settings);
        $data = $plan->data ?? Plan::emptyData();

        // Columns hidden in the plan (notes, who …) are not in the shared page at all — not just unshown
        $hidden = array_keys(array_filter(['who' => ! $settings['columns']['who'], 'notes' => ! $settings['columns']['notes']]));
        $data['rows'] = array_map(fn (array $row) => array_diff_key($row, array_flip($hidden)), $data['rows'] ?? []);

        return Inertia::render('Plans/Public', [
            'plan' => [
                'name' => $plan->name,
                'description' => $plan->description,
                'project' => $plan->project?->name,
                'color' => $plan->project?->color,
                'data' => $data,
                'settings' => $settings,
                'updated_at' => $plan->updated_at?->toIso8601String(),
            ],
            'operator' => PublicOperator::for($plan->project?->workspace), // who shares it (BRAI-156)
        ]);
    }
}
