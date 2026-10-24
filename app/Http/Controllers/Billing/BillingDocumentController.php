<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\BillingReport;
use App\Models\Workspace;
use Illuminate\Support\Facades\Gate;

/**
 * Billing documents (v1.38.0, BRAI-101 documents): PDF and Excel of a billing report and sending it
 * to the client. Renders the report's `snapshot` (finalized) or the live draft — same shape, see
 * docs/ai/tasks/release-v1.38.0-billing.md. Foundation stub: the documents ticket implements it.
 */
class BillingDocumentController extends Controller
{
    public function pdf(Workspace $workspace, int $billingReport)
    {
        $this->report($workspace, $billingReport);
        abort(501);
    }

    public function xlsx(Workspace $workspace, int $billingReport)
    {
        $this->report($workspace, $billingReport);
        abort(501);
    }

    public function send(Workspace $workspace, int $billingReport)
    {
        $this->report($workspace, $billingReport);
        abort(501);
    }

    protected function report(Workspace $workspace, int $id): BillingReport
    {
        Gate::authorize('manageBilling', $workspace);

        return BillingReport::where('workspace_id', $workspace->id)->findOrFail($id);
    }
}
