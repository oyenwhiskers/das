<?php

namespace App\Services;

use App\Models\AttachmentScan;
use App\Models\DocumentScan;
use FilesystemIterator;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;
use UnexpectedValueException;

class StorageUsageReport
{
    /**
     * @return array{
     *     path: string,
     *     mount: string,
     *     available: bool,
     *     free: float,
     *     used: float,
     *     total: float,
     *     usedPercent: float,
     *     app: int,
     *     documents: int,
     *     attachments: int,
     *     documentCount: int,
     *     attachmentCount: int,
     *     other: int
     * }
     */
    public function report(): array
    {
        $disk = Storage::disk('local');
        $path = $disk->path('');
        // Docker mounts the persistent volume at storage/app; local files live under private/.
        $mount = storage_path('app');

        if (! is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        $resolved = realpath($path) ?: $path;
        $probe = is_dir($mount) ? (realpath($mount) ?: $mount) : $resolved;
        [$free, $total, $available] = $this->volume($probe);

        $documents = (int) DocumentScan::query()->sum('file_size');
        $attachments = (int) AttachmentScan::query()->sum('file_size');
        $app = $this->directorySize($resolved);

        return [
            'path' => $resolved,
            'mount' => $probe,
            'available' => $available,
            'free' => $free,
            'used' => max(0, $total - $free),
            'total' => $total,
            'usedPercent' => $total > 0 ? round((($total - $free) / $total) * 100, 1) : 0.0,
            'app' => $app,
            'documents' => $documents,
            'attachments' => $attachments,
            'documentCount' => DocumentScan::query()->count(),
            'attachmentCount' => AttachmentScan::query()->count(),
            'other' => max(0, $app - $documents - $attachments),
        ];
    }

    /** @return array{0: float, 1: float, 2: bool} */
    private function volume(string $path): array
    {
        if (! function_exists('disk_free_space') || ! function_exists('disk_total_space')) {
            return [0.0, 0.0, false];
        }

        $free = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || (float) $total <= 0) {
            return [0.0, 0.0, false];
        }

        return [(float) $free, (float) $total, true];
    }

    private function directorySize(string $path): int
    {
        if (! is_dir($path)) {
            return 0;
        }

        $bytes = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST,
                RecursiveIteratorIterator::CATCH_GET_CHILD,
            );
        } catch (UnexpectedValueException) {
            return 0;
        }

        foreach ($iterator as $file) {
            try {
                if ($file->isLink() || ! $file->isFile()) {
                    continue;
                }
                $bytes += $file->getSize();
            } catch (Throwable) {
                // Unreadable entries on locked-down hosts should not take the page down.
            }
        }

        return $bytes;
    }
}
