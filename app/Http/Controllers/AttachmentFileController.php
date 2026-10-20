<?php

namespace App\Http\Controllers;

use App\Models\AttachmentScan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentFileController extends Controller
{
    public function __invoke(AttachmentScan $attachmentScan): StreamedResponse
    {
        Gate::authorize('view', $attachmentScan);

        abort_unless(Storage::disk('local')->exists($attachmentScan->storage_path), 404);

        return Storage::disk('local')->response(
            $attachmentScan->storage_path,
            $attachmentScan->original_filename,
            [
                'Content-Type' => $attachmentScan->mime_type,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
            request()->boolean('download') ? 'attachment' : 'inline',
        );
    }
}
