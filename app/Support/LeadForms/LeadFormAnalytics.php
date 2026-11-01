<?php

namespace App\Support\LeadForms;

use App\Models\LeadForm;
use App\Models\LeadFormEvent;
use Illuminate\Support\Facades\DB;

/**
 * A lead form's funnel for the last 7 / 30 / 90 days (BRAI-93): unique sessions that viewed, started and
 * submitted, conversion, leads, a daily views / submits series and how far visitors got per step. Shared by
 * the web's Analytics tab (LeadFormController::analytics) and the App API (BRAI-421).
 */
final class LeadFormAnalytics
{
    public const RANGES = [7, 30, 90];

    /**
     * @return array{days: int, views: int, starts: int, submits: int, conversion: float|null, leads: int, series: list<array{date: string, views: int, submits: int}>, steps: list<array{index: int, title: string, conditional: bool, sessions: int}>}
     */
    public static function summary(LeadForm $form, int $days): array
    {
        $since = now()->subDays($days - 1)->startOfDay();
        $events = LeadFormEvent::where('lead_form_id', $form->id)->where('created_at', '>=', $since);

        $totals = (clone $events)->select('type', DB::raw('count(distinct session_id) as sessions'))
            ->groupBy('type')->pluck('sessions', 'type');

        $daily = (clone $events)->whereIn('type', ['view', 'submit'])
            ->select(DB::raw('date(created_at) as day'), 'type', DB::raw('count(distinct session_id) as sessions'))
            ->groupBy('day', 'type')->get();

        $series = [];
        for ($d = $since->copy(); $d->lte(now()); $d->addDay()) {
            $series[$d->toDateString()] = ['date' => $d->toDateString(), 'views' => 0, 'submits' => 0];
        }
        foreach ($daily as $row) {
            $day = substr((string) $row->day, 0, 10);
            if (isset($series[$day])) {
                $series[$day][$row->type === 'view' ? 'views' : 'submits'] = (int) $row->sessions;
            }
        }

        $reached = (clone $events)->where('type', 'step')
            ->select('step', DB::raw('count(distinct session_id) as sessions'))
            ->groupBy('step')->pluck('sessions', 'step');

        $steps = [];
        foreach ($form->definition['steps'] ?? [] as $i => $step) {
            $steps[] = [
                'index' => $i,
                'title' => $step['title'],
                'conditional' => ! empty($step['visible_if']), // skipped by some visitors: not a drop-off
                'sessions' => (int) ($reached[$i] ?? ($i === 0 ? ($totals['start'] ?? 0) : 0)),
            ];
        }

        $views = (int) ($totals['view'] ?? 0);
        $submits = (int) ($totals['submit'] ?? 0);

        return [
            'days' => $days,
            'views' => $views,
            'starts' => (int) ($totals['start'] ?? 0),
            'submits' => $submits,
            'conversion' => $views > 0 ? round($submits / $views * 100, 1) : null,
            'leads' => $form->leads()->where('created_at', '>=', $since)->count(),
            'series' => array_values($series),
            'steps' => $steps,
        ];
    }
}
