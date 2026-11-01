<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\UpdateMeRequest;
use App\Http\Resources\AppApi\MeResource;
use App\Models\User;
use Illuminate\Http\Request;

/** The signed-in account in the desktop / mobile apps (BRAI-384). */
class MeController extends Controller
{
    /** The signed-in user, their workspaces and limits, and the current device. */
    public function show(Request $request): MeResource
    {
        return $this->me($request->user());
    }

    /** Change the profile (name, bio, phone) and preferences (time zone, daily digest). */
    public function update(UpdateMeRequest $request): MeResource
    {
        $user = $request->user();
        $user->update($request->validated());

        return $this->me($user);
    }

    private function me(User $user): MeResource
    {
        return MeResource::make($user->load('workspaces'));
    }
}
