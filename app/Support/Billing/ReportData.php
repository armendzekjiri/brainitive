<?php

namespace App\Support\Billing;

use App\Contracts\Billing\BuildsReportData;
use App\Models\BillingReport;

/**
 * Where a billing document's data comes from: the frozen `snapshot` once a report is finalized
 * (also sent / paid / void), the live builder while it is a draft. Same shape either way
 * (snapshot v1, docs/ai/tasks/release-v1.38.0-billing.md).
 */
class ReportData
{
    /** @return array<string, mixed> */
    public static function for(BillingReport $report): array
    {
        if ($report->isDraft()) {
            abort_unless(app()->bound(BuildsReportData::class), 409, 'Draft preview not available');

            return app(BuildsReportData::class)->build($report);
        }

        abort_unless(is_array($report->snapshot) && $report->snapshot !== [], 409, 'This report has no document data.');

        return $report->snapshot;
    }
}
