<?php

namespace App\Contracts\Billing;

use App\Models\BillingReport;

/**
 * Builds the live data of a draft billing report: the "BillingReport snapshot v1" array of
 * docs/ai/tasks/release-v1.38.0-billing.md (the same shape finalizing stores in `snapshot`).
 * Implemented by App\Support\Billing\ReportBuilder (reports ticket); bound in BillingServiceProvider
 * only when that class exists, so documents of drafts answer 409 until it does.
 */
interface BuildsReportData
{
    /** @return array<string, mixed> */
    public function build(BillingReport $report): array;
}
