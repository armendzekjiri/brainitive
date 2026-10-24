<?php

namespace App\Providers;

use App\Contracts\Billing\BuildsReportData;
use App\Models\Workspace;
use App\Support\Billing\LiveReportData;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Client billing (v1.38.0) container bindings and limits. Draft documents get their data from the
 * reports ticket's ReportBuilder through BuildsReportData; without it, a draft's PDF / Excel answers 409.
 */
class BillingServiceProvider extends ServiceProvider
{
    /** Report mails a workspace may send per day (anyone can register and own a workspace). */
    public const SENDS_PER_WORKSPACE_PER_DAY = 50;

    public function register(): void
    {
        if (LiveReportData::available() && ! $this->app->bound(BuildsReportData::class)) {
            $this->app->bind(BuildsReportData::class, LiveReportData::class);
        }
    }

    public function boot(): void
    {
        // throttle:billing-send — 10 a minute per admin and a daily cap per workspace, so the
        // app's mail-from can't be used to send invoices in bulk
        RateLimiter::for('billing-send', function (Request $request) {
            $workspace = $request->route('workspace');
            $workspaceKey = $workspace instanceof Workspace ? $workspace->id : (string) $workspace;

            return [
                Limit::perMinute(10)->by('billing-send:user:'.($request->user()?->id ?? $request->ip())),
                Limit::perDay(self::SENDS_PER_WORKSPACE_PER_DAY)->by('billing-send:workspace:'.$workspaceKey),
            ];
        });
    }
}
