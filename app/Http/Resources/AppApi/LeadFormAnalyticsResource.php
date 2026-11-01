<?php

namespace App\Http\Resources\AppApi;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lead form's funnel (LeadFormAnalytics::summary): unique sessions that viewed / started / submitted, the
 * conversion in %, leads, a daily series and how many sessions reached each step. No visitor data.
 *
 * @property-read array{days: int, views: int, starts: int, submits: int, conversion: float|null, leads: int, series: list<array{date: string, views: int, submits: int}>, steps: list<array{index: int, title: string, conditional: bool, sessions: int}>} $resource
 */
class LeadFormAnalyticsResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $a = $this->resource;

        return [
            'days' => (int) $a['days'],
            'views' => (int) $a['views'],
            'starts' => (int) $a['starts'],
            'submits' => (int) $a['submits'],
            // submits / views in %, one decimal; null without views
            'conversion' => $a['conversion'] === null ? null : (float) $a['conversion'],
            // leads created in the range (any source)
            'leads' => (int) $a['leads'],
            // one entry per day (the server's dates), oldest first
            'series' => array_map(fn (array $d) => ['date' => (string) $d['date'], 'views' => (int) $d['views'], 'submits' => (int) $d['submits']], $a['series']),
            // sessions that reached each step; conditional steps are skipped by some visitors (not a drop-off)
            'steps' => array_map(fn (array $s) => ['index' => (int) $s['index'], 'title' => (string) $s['title'], 'conditional' => (bool) $s['conditional'], 'sessions' => (int) $s['sessions']], $a['steps']),
        ];
    }
}
