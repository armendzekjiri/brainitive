<?php

namespace App\Mcp\Tools\Concerns;

use App\Models\Plan;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Authenticatable;

trait InteractsWithPlans
{
    use InteractsWithBrainitive;

    /**
     * @throws AuthorizationException
     */
    protected function planForUser(Authenticatable $user, string $workspace, string $project, string $slug, string $ability): Plan
    {
        $project = $this->projectForUser($user, $workspace, $project);
        $plan = $project->plans()->where('slug', $slug)->first();
        if (! $plan) {
            throw new AuthorizationException("No plan named \"{$slug}\" in this project. Call list_plans.");
        }
        $this->authorizeFor($user, $ability, $plan);

        return $plan;
    }

    protected function planUrl(Plan $plan): string
    {
        $project = $plan->project;

        return route('plans.show', [$project->workspace->slug, $project->slug, $plan->slug]);
    }

    /**
     * @return array{start: string, end: string}
     */
    protected function planRange(Plan $plan): array
    {
        $dates = collect($plan->data['rows'] ?? [])->flatMap(fn ($row) => $row['bars'] ?? []);

        return ['start' => (string) ($dates->min('start') ?? ''), 'end' => (string) ($dates->max('end') ?? '')];
    }
}
