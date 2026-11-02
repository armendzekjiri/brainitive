<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AppApi\AppTokens;
use App\Support\Live\LiveChannels;
use App\Support\Live\LiveConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Internal API for the realtime server (BRAI-151), behind VerifyCollabSecret: every minute it asks
 * which of their channels each connected user may still use, and drops the rest. Users that no
 * longer exist (or are suspended) get an empty list. Entries may list the app devices of the user's
 * sockets (`devices`, tokens with `dev`); the answer's `revoked_devices` names those that are revoked,
 * gone or not that user's, and the realtime server closes their sockets (4410).
 */
class LiveInternalController extends Controller
{
    public function access(Request $request): JsonResponse
    {
        abort_unless(LiveConfig::configured(), 404);

        $validated = $request->validate([
            'users' => 'present|array|max:100',
            'users.*.id' => 'required|integer|min:1',
            'users.*.channels' => 'present|array|max:100',
            'users.*.channels.*' => 'string|max:100',
            'users.*.devices' => 'sometimes|array|max:20',
            'users.*.devices.*' => 'integer|min:1',
        ]);

        $ids = array_unique(array_map(fn (array $entry) => (int) $entry['id'], $validated['users']));
        $users = User::whereIn('id', $ids)->get()->keyBy('id');
        $channels = new LiveChannels;
        $access = [];
        // a user with many tabs may come in several entries: their answers add up
        foreach ($validated['users'] as $entry) {
            $user = $users->get((int) $entry['id']);
            $allowed = $user ? $channels->authorize($user, $entry['channels']) : [];
            $access[(string) $entry['id']] = array_values(array_unique([...($access[(string) $entry['id']] ?? []), ...$allowed]));
        }

        return response()->json(['users' => (object) $access, 'revoked_devices' => $this->revokedDevices($validated['users'])]);
    }

    /**
     * The asked devices that may no longer hold a socket: revoked, deleted, or not the entry's user.
     *
     * @param  list<array{id: int|string, devices?: list<int|string>}>  $entries
     * @return list<int>
     */
    private function revokedDevices(array $entries): array
    {
        $asked = [];
        foreach ($entries as $entry) {
            foreach ($entry['devices'] ?? [] as $device) {
                $asked[(int) $device] = (int) $entry['id'];
            }
        }

        return AppTokens::revokedAmong($asked);
    }
}
