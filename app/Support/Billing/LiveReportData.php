<?php

namespace App\Support\Billing;

use App\Contracts\Billing\BuildsReportData;
use App\Models\BillingReport;

/**
 * Adapter from BuildsReportData to the reports ticket's ReportBuilder, whatever its exact form
 * (instance or static build()). BillingServiceProvider binds it only when the builder class exists.
 */
class LiveReportData implements BuildsReportData
{
    public const BUILDER = 'App\\Support\\Billing\\ReportBuilder';

    public static function available(): bool
    {
        return class_exists(self::BUILDER);
    }

    public function build(BillingReport $report): array
    {
        return app(self::BUILDER)->build($report);
    }
}
