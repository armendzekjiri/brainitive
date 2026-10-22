<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use Inertia\Inertia;
use Inertia\Response;

class AuditLogController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Admin/AuditLog', [
            'entries' => AdminAuditLog::query()
                ->latest('id')
                ->paginate(50)
                ->through(fn (AdminAuditLog $entry) => [
                    'id' => $entry->id,
                    'actor_name' => $entry->actor_name,
                    'action' => $entry->action,
                    'target_type' => $entry->target_type,
                    'target_label' => $entry->target_label,
                    'meta' => $entry->meta,
                    'ip_address' => $entry->ip_address,
                    'created_at' => $entry->created_at?->toIso8601String(),
                ]),
        ]);
    }
}
