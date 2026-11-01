<?php

namespace App\Http\Resources\AppApi;

use App\Models\Plan;
use App\Support\Plans\PlanReader;
use Illuminate\Http\Request;

/**
 * One Gantt plan with its rows, bars and dependencies (`GET /plans/{id}`, PlanReader — the MCP's get_plan).
 *
 * @mixin Plan
 */
class PlanDetailResource extends PlanResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $read = PlanReader::read($this->resource, $request->user());

        return [
            ...parent::toArray($request),
            'rows' => PlanRowResource::collection($read['rows']),
            'dependencies' => PlanDependencyResource::collection($read['dependencies']),
            'can' => [
                // editing the plan: web only
                'update' => $request->user()->can('update', $this->resource),
            ],
        ];
    }
}
