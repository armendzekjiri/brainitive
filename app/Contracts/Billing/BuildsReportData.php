<?php

namespace App\Contracts\Billing;

use App\Models\BillingReport;

/**
 * Builds the billing report snapshot v1 array (docs/ai/tasks/release-v1.38.0-billing.md): live for
 * a draft, from the locked entries for a finalized report. Documents render exactly this array.
 */
interface BuildsReportData
{
    public function build(BillingReport $report): array;
}
