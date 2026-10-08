<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Uploaded paperwork (LPOs, certificates, signed scans, contract scans, technician documents) is private.
 * It is kept off the public web folder and only served through /files/..., which checks who is asking.
 */
class Files
{
    public const DISK = 'local';

    public static function put(UploadedFile $file, string $directory): string
    {
        return $file->store($directory, self::DISK);
    }

    public static function url(?string $path): string
    {
        return $path ? route('files.show', ['path' => ltrim($path, '/')]) : '#';
    }

    /** Remove a file from wherever it lives, private or (for older uploads) public. */
    public static function delete(?string $path): void
    {
        if (! $path) {
            return;
        }

        foreach ([self::DISK, 'public'] as $disk) {
            Storage::disk($disk)->delete($path);
        }
    }
}
