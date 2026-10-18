<?php

namespace App\Http\Controllers;

use App\Models\DocumentScan;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentFileController extends Controller
{
    public function __invoke(DocumentScan $documentScan): StreamedResponse
    {
        Gate::authorize('view', $documentScan);

        abort_unless(Storage::disk('local')->exists($documentScan->storage_path), 404);

        return Storage::disk('local')->download($documentScan->storage_path, $documentScan->original_filename, [
            'Content-Type' => $documentScan->mime_type,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
