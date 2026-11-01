<?php

namespace App\Support\Profile;

use App\Models\User;
use App\Support\Uploads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A user's profile picture on the public disk (`profile-photos/{id}/avatar.{ext}`): the web Profile
 * page and the App API (`/api/v1/me/avatar`) both go through here. The extension comes from the
 * file's detected type, never the client's name (KNOWLEDGE "Uploads"). Callers save the user.
 */
final class Avatar
{
    public static function replace(User $user, UploadedFile $file): void
    {
        self::deleteFile($user);

        $user->avatar = $file->storeAs('profile-photos/'.$user->id, 'avatar.'.Uploads::imageExtension($file), 'public');
    }

    public static function remove(User $user): void
    {
        self::deleteFile($user);

        $user->avatar = null;
    }

    private static function deleteFile(User $user): void
    {
        if ($user->avatar && Storage::disk('public')->exists($user->avatar)) {
            Storage::disk('public')->delete($user->avatar);
        }
    }
}
