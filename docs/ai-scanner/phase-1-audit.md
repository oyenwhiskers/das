# AI Document Reader — Phase 1 foundation

**Updated:** 16 October 2026. The original brief assumed an existing Gemini scanner; the corrected brief confirmed this is its first implementation. The earlier absent-scanner audit is superseded by this implementation record.

## Existing application context

The repository was a Laravel 13 customer profile application with Livewire screens, `auth` and `active` route middleware, policies, a private `local` disk, and a database queue. No scanner code or document import tests existed before this phase. The pre-change suite passed 160 tests and 750 assertions.

## Phase 1 workflow and boundaries

1. [Document list and upload](../../app/Livewire/Documents/Index.php) authorizes staff, validates PDF/JPEG/PNG MIME type, extension and configured 10 MB limit, stores the original on the private `local` disk, and creates a pending [DocumentScan](../../app/Models/DocumentScan.php). The generated storage path is independent of the supplied filename.
2. [ProcessDocumentScan](../../app/Jobs/ProcessDocumentScan.php) runs on Laravel's queue. It records processing start and model, invokes the [Gemini reader](../../app/Services/GeminiDocumentReader.php), then stores only the validated basic result. Malformed responses fail permanently; connection and API failures are retried up to three attempts and eventually marked failed. A unique job key and terminal-status guard prevent routine duplicate processing.
3. The Gemini reader sends inline PDF or image data to Google's Interactions API, requests JSON with only document type, title, summary, readable and limitations, and sets `store=false`. It does not query or write customer tables.
4. [Document detail](../../app/Livewire/Documents/Show.php) polls while processing and shows status, metadata and the basic result. [DocumentFileController](../../app/Http/Controllers/DocumentFileController.php) authorizes an original-file download. [DocumentScanPolicy](../../app/Policies/DocumentScanPolicy.php) follows the existing active-staff access pattern.

The [migration](../../database/migrations/2026_10_16_101409_create_document_scans_table.php) creates a standalone record with uploader, private path, filename, MIME, size, status, model, basic result and processing timestamps. It has no customer foreign key. No customer matching, approval or profile import was added.

## Configuration and operation

- Set `GEMINI_API_KEY` on the server. `GEMINI_MODEL` defaults to `gemini-3.1-flash-lite`; `DOCUMENT_SCAN_MAX_KB` defaults to 10240. See [services configuration](../../config/services.php), [scanner configuration](../../config/document_scanner.php) and [.env.example](../../.env.example).
- The database queue needs a running worker, for example `php artisan queue:work --tries=3 --timeout=120`. The database queue `retry_after` defaults to 150 seconds, leaving a margin beyond the job timeout. Production must supervise the worker and use a shared lock-capable cache for unique jobs.
- Apply migrations with `php artisan migrate --no-interaction`. The new migration was applied in the development database during this phase.
- For an optional live smoke test, set a real API key, run a queue worker, upload a non-sensitive sample PDF or image through the Documents page, and check that the record moves from Pending to Completed with a plausible summary. This live call was not performed during automated testing.

Google's [document input guide](https://ai.google.dev/gemini-api/docs/document-processing?lang=rest), [structured output guide](https://ai.google.dev/gemini-api/docs/structured-output), and [Interactions API retention guide](https://ai.google.dev/gemini-api/docs/interactions-overview) support the selected inline input and stateless JSON request shape. The 10 MB limit keeps the base64 request below the 20 MB inline guidance. DAS retains original files privately; a deletion policy remains undecided.

## Verification and next phase

The focused scanner suite passed 15 tests and 73 assertions. The full suite passed 175 tests and 823 assertions. Pint passed, the document routes resolved, and Vite built. Gemini connectivity remains unverified without credentials and a live request.

Phase 2 can add a universal findings schema with page evidence and richer extraction. The current summary is only a first-stage document-level result, and the UI has no findings review, customer matching or profile write path. Livewire's temporary-upload limit and PHP server upload limits must be raised separately if operators configure a maximum above their current thresholds.
