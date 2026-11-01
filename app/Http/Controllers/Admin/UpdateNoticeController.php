<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Updates\UpdateCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BRAI-433: hide the "new version available" strip for this admin until a newer release than the
 * dismissed one comes out. The admin console's Overview keeps showing the update either way.
 */
class UpdateNoticeController extends Controller
{
    public function dismiss(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'version' => ['required', 'string', 'regex:/^v?\d{1,4}\.\d{1,4}\.\d{1,4}$/'],
        ]);

        UpdateCheck::dismiss($request->user(), $validated['version']);

        return response()->json(['ok' => true]);
    }
}
