<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Safe names for files stored on the public disk. Everything there is served straight from
 * /storage, so a stored name must never let the web server run it (FrankenPHP / php-fpm
 * execute any *.php under public/) or let a browser render it as a page on our origin
 * (HTML, SVG, XML). Never store a client-supplied name or extension as-is.
 */
class Uploads
{
    /** Extensions a web server could execute or a browser could render as active content. */
    private const ACTIVE = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phps', 'phpt', 'phar', 'pgif', 'inc',
        'htaccess', 'htpasswd', 'user.ini', 'ini',
        'html', 'htm', 'xhtml', 'xht', 'shtml', 'shtm', 'stm', 'svg', 'svgz', 'xml', 'xsl', 'xslt', 'mht', 'mhtml',
        'js', 'mjs', 'cjs', 'jsp', 'jspx', 'asp', 'aspx', 'cer', 'asa', 'cgi', 'pl', 'py', 'rb', 'sh', 'bash', 'swf',
    ];

    private const IMAGE = ['jpeg' => 'jpg', 'jpg' => 'jpg', 'png' => 'png', 'gif' => 'gif', 'webp' => 'webp', 'bmp' => 'bmp', 'avif' => 'avif'];

    /** `<uuid>.<ext>` with the client's extension when it's harmless, else `.bin`. */
    public static function name(UploadedFile $file): string
    {
        return Str::uuid().'.'.self::extension($file);
    }

    /** `<uuid>.<ext>` for images, the extension taken from the file's content. */
    public static function imageName(UploadedFile $file): string
    {
        return Str::uuid().'.'.self::imageExtension($file);
    }

    public static function extension(UploadedFile $file): string
    {
        $ext = strtolower($file->getClientOriginalExtension());

        return preg_match('/^[a-z0-9]{1,10}$/', $ext) && ! in_array($ext, self::ACTIVE, true) ? $ext : 'bin';
    }

    /** From the detected MIME type, never the client's name — a PNG named x.php stays .png. */
    public static function imageExtension(UploadedFile $file): string
    {
        return self::IMAGE[strtolower((string) $file->guessExtension())] ?? 'bin';
    }

    /** The client's file name for display only: no path, no control characters. */
    public static function displayName(UploadedFile $file): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $file->getClientOriginalName())));

        return Str::limit($name !== '' ? $name : 'file', 250, '');
    }

    public static function isActive(string $path): bool
    {
        $name = strtolower(basename($path));

        foreach (self::ACTIVE as $ext) {
            if (str_ends_with($name, '.'.$ext) || str_contains($name, '.'.$ext.'.')) {
                return true;
            }
        }

        return false;
    }
}
