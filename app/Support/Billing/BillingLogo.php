<?php

namespace App\Support\Billing;

use App\Models\BillingReport;
use App\Models\BillingSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The workspace logo printed on billing documents. Stored on the private `local` disk (never the
 * public one) as billing-logos/<workspace id>/<uuid>.png, always re-encoded with GD: that drops
 * metadata and anything appended to the image, and the file we keep is a plain PNG whatever was
 * uploaded. Documents embed it as a data URI, so dompdf never reads a file or URL itself.
 */
class BillingLogo
{
    public const DISK = 'local';

    /** Uploads larger than this per side are refused before decoding (memory). */
    public const MAX_SOURCE_SIDE = 2500;

    /** Stored logos are scaled down to fit this box. */
    private const MAX_WIDTH = 1200;

    private const MAX_HEIGHT = 600;

    public static function store(BillingSetting $settings, UploadedFile $file): string
    {
        $png = self::reencode((string) file_get_contents($file->getRealPath()));
        $path = 'billing-logos/'.$settings->workspace_id.'/'.Str::uuid().'.png';
        Storage::disk(self::DISK)->put($path, $png);

        $old = $settings->logo_path;
        $settings->forceFill(['logo_path' => $path])->save();
        self::discard($settings->workspace_id, $old);

        return $path;
    }

    public static function remove(BillingSetting $settings): void
    {
        $old = $settings->logo_path;
        if ($settings->exists) {
            $settings->forceFill(['logo_path' => null])->save();
        }
        self::discard($settings->workspace_id, $old);
    }

    /** `data:image/png;base64,…` for a logo of this workspace, null when there is none (or it's gone). */
    public static function dataUri(?string $path, int $workspaceId): ?string
    {
        if (! self::owns($path, $workspaceId) || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return 'data:image/png;base64,'.base64_encode((string) Storage::disk(self::DISK)->get($path));
    }

    /** Only paths this class writes, inside the workspace's own folder (a snapshot is data, not a path to trust). */
    public static function owns(?string $path, int $workspaceId): bool
    {
        return is_string($path)
            && preg_match('#^billing-logos/'.$workspaceId.'/[0-9a-f-]{36}\.png$#', $path) === 1;
    }

    /**
     * Delete a replaced logo unless a finalized document still prints it (its snapshot keeps the
     * path, so an old invoice re-downloads with the logo it was issued with).
     */
    private static function discard(int $workspaceId, ?string $path): void
    {
        if (! self::owns($path, $workspaceId)) {
            return;
        }
        $stillPrinted = BillingReport::where('workspace_id', $workspaceId)
            ->where('status', '!=', 'draft')
            ->where('snapshot->issuer->logo_path', $path)
            ->exists();
        if (! $stillPrinted) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    /** Decode png / jpg / webp, scale into the box, encode as PNG (alpha kept). */
    private static function reencode(string $bytes): string
    {
        $info = @getimagesizefromstring($bytes);
        $allowed = [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP];
        if (! $info || ! in_array($info[2], $allowed, true)
            || $info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_SOURCE_SIDE || $info[1] > self::MAX_SOURCE_SIDE) {
            throw ValidationException::withMessages(['logo' => 'The logo must be a PNG, JPG or WebP image up to '.self::MAX_SOURCE_SIDE.' pixels per side.']);
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw ValidationException::withMessages(['logo' => 'The logo could not be read as an image.']);
        }

        [$width, $height] = [imagesx($source), imagesy($source)];
        $scale = min(1, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 255, 255, 255, 127));
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagepng($target, null, 9);
        $png = (string) ob_get_clean();

        return $png;
    }
}
