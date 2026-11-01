<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A project's health (BRAI-64): the live metrics of the period (HealthMetrics::compute: open, completed, overdue,
 * due soon, unassigned, blocked, milestones, late plan rows, with the lists behind them — only what the user can
 * open) and the saved status updates, newest first.
 *
 * @property-read array{from: string, to: string, timezone: string, metrics: array<string, mixed>, reports: list<array{id: int, status: string, summary: string|null, author: string|null, as_of: string|null, created_at: string|null, ai_assisted: bool}>, can_create: bool, url: string} $resource
 */
class ProjectHealthResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'from' => (string) $r['from'],
            'to' => (string) $r['to'],
            'timezone' => (string) $r['timezone'],
            // values, unknown and lists per metric, as the web's Health page
            'metrics' => $r['metrics'],
            // status updates: {id, status: on_track|at_risk|off_track, summary, author, as_of, created_at, ai_assisted}
            'reports' => array_map(fn (array $x) => [
                'id' => (int) $x['id'],
                'status' => (string) $x['status'],
                'summary' => $x['summary'],
                'author' => $x['author'],
                'as_of' => $x['as_of'],
                'created_at' => $x['created_at'],
                'ai_assisted' => (bool) $x['ai_assisted'],
            ], $r['reports']),
            // writing a status update (web only for now)
            'can_create' => (bool) $r['can_create'],
            'url' => (string) $r['url'],
        ];
    }
}
