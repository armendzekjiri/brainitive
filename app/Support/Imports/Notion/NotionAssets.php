<?php

namespace App\Support\Imports\Notion;

use App\Support\Uploads;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Ramsey\Uuid\Uuid;

/**
 * Images and files inside a Notion export, stored exactly like editor uploads (BRAI-66): the same
 * public disk and folders, the same validation rules as FileController::uploadImage /
 * uploadDocument, and safe names from Uploads (an image's extension from its content).
 *
 * The stored name is a UUIDv5 of (run, path in the archive), so a resumed run finds the file it
 * already stored instead of storing it twice, and a file used by several pages is stored once.
 */
final class NotionAssets
{
    /** FileController::uploadImage */
    private const IMAGE_RULES = ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:10240'];

    /** FileController::uploadDocument */
    private const DOCUMENT_RULES = ['required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv,zip,rar', 'max:20480'];

    /** @var array<string, ?string> path|kind → url (null = not allowed) */
    private array $seen = [];

    /** @var array<string, string> stored file → images|files, over every attempt of the run */
    private array $stored = [];

    /**
     * @param  string|null  $ledger  null: preview (nothing is stored); else a JSON file in the run's folder
     *                               listing what was stored, so a resumed run counts every file once
     */
    public function __construct(private readonly NotionArchive $archive, private readonly string $runUuid, private readonly ?string $ledger = null)
    {
        if ($ledger !== null && is_file($ledger)) {
            $this->stored = (array) json_decode((string) file_get_contents($ledger), true);
        }
    }

    /** The URL of the stored file, a placeholder in preview mode, or null when its type isn't allowed. */
    public function url(string $path, bool $image): ?string
    {
        $cacheKey = $path.'|'.($image ? 'image' : 'file');
        if (array_key_exists($cacheKey, $this->seen)) {
            return $this->seen[$cacheKey];
        }

        $file = new UploadedFile($this->archive->path($path), basename($path), null, null, true);
        $asImage = $image || Validator::make(['file' => $file], ['file' => self::IMAGE_RULES])->passes();
        if (! Validator::make(['file' => $file], ['file' => $asImage ? self::IMAGE_RULES : self::DOCUMENT_RULES])->passes()) {
            return $this->seen[$cacheKey] = null;
        }
        if ($this->ledger === null) {
            return $this->seen[$cacheKey] = '#';
        }

        $directory = $asImage ? 'uploads/images' : 'uploads/documents';
        $name = Uuid::uuid5(Uuid::NAMESPACE_URL, 'notion-import:'.$this->runUuid.':'.$path)->toString()
            .'.'.($asImage ? Uploads::imageExtension($file) : Uploads::extension($file));
        $disk = Storage::disk('public');
        if (! $disk->exists($directory.'/'.$name)) {
            $disk->putFileAs($directory, $file, $name);
        }
        if (! isset($this->stored[$directory.'/'.$name])) {
            $this->stored[$directory.'/'.$name] = $asImage ? 'images' : 'files';
            file_put_contents($this->ledger, json_encode($this->stored));
        }

        return $this->seen[$cacheKey] = Storage::url($directory.'/'.$name); // like FileController
    }

    /** @return array{images: int, files: int} distinct files accepted (preview) or stored (import) so far */
    public function accepted(): array
    {
        $counts = ['images' => 0, 'files' => 0];
        if ($this->ledger !== null) {
            foreach ($this->stored as $kind) {
                $counts[$kind]++;
            }

            return $counts;
        }
        foreach ($this->seen as $key => $url) {
            if ($url !== null) {
                $counts[str_ends_with($key, '|image') ? 'images' : 'files']++;
            }
        }

        return $counts;
    }
}
