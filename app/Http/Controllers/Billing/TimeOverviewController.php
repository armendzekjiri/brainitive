<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingSetting;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Billing\TimeExport;
use App\Support\Billing\TimeOverview;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Workspace → Billing → Time (BRAI-100, workspace admins): the month-end overview of everyone's
 * logged time, with filters, grouping, an indicative amount and the Excel export.
 */
class TimeOverviewController extends Controller
{
    public function index(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('manageBilling', $workspace);

        $overview = TimeOverview::fromRequest($request, $workspace);
        $user = $request->user();
        $today = CarbonImmutable::now($user instanceof User ? $user->localTimezone() : config('app.timezone'))->startOfDay();
        $presets = [];
        foreach (['last_month', 'this_month'] as $preset) {
            [$from, $to] = TimeOverview::presetRange($preset, $today);
            $presets[$preset] = ['from' => $from->toDateString(), 'to' => $to->toDateString()];
        }

        return Inertia::render('Billing/Time/Index', [
            'workspace' => $workspace->only(['id', 'name', 'slug']),
            'filters' => $overview->filters(),
            'presets' => $presets,
            'summary' => $overview->summary(),
            'options' => $overview->options(),
            'currency' => BillingSetting::forWorkspace($workspace)->currency ?: 'EUR',
            'detailLimit' => TimeOverview::DETAIL_LIMIT,
        ]);
    }

    public function export(Request $request, Workspace $workspace): StreamedResponse
    {
        Gate::authorize('manageBilling', $workspace);

        return TimeExport::download(TimeOverview::fromRequest($request, $workspace));
    }
}
