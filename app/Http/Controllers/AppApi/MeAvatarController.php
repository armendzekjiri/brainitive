<?php

namespace App\Http\Controllers\AppApi;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppApi\UpdateAvatarRequest;
use App\Http\Resources\AppApi\MeResource;
use App\Support\Profile\Avatar;
use Illuminate\Http\Request;

/** The profile picture from the desktop / mobile apps (BRAI-384): same storage as the web Profile page. */
class MeAvatarController extends Controller
{
    /** Upload a new profile picture (multipart `avatar`: JPEG or PNG, at most 2 MB). */
    public function store(UpdateAvatarRequest $request): MeResource
    {
        $user = $request->user();
        Avatar::replace($user, $request->file('avatar'));
        $user->save();

        return MeResource::make($user->load('workspaces'));
    }

    /** Remove the profile picture. */
    public function destroy(Request $request): MeResource
    {
        $user = $request->user();
        Avatar::remove($user);
        $user->save();

        return MeResource::make($user->load('workspaces'));
    }
}
