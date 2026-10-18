# DocumentFindingsV1

`DocumentFindingsV1` is the version `1.0` contract for original structured observations from a scanned document. It has no Customer Profile fields or institution-specific labels. Phase 2 provides validation and storage. Phase 1 produces classification, summary, and readability; Phase 3 runs detailed Gemini extraction afterward for readable documents.

## Document and extraction state

The required top-level keys are `schema_version`, `document`, `pages`, `sections`, `entities`, `fields`, `tables`, `content_blocks`, and `issues`. Collection keys are always present and may be empty. Unknown top-level keys and unsupported versions are rejected.

`document.reference` is the existing `document_scans.id` as a string. `document.page_count` is a positive integer, `document.reading_status` is `complete`, `partial`, `unreadable`, or `unknown`, and `document.limitations` is a nullable string. The scan remains the source of truth for document type, title, summary, file, and Phase 1 readability/limitations. Every page from 1 through `page_count` has a page record with its reading status, section IDs, and free-text reading issues.

`document_scans.extraction_status` starts as `not_started`. After a readable Phase 1 result, `ProcessDocumentExtraction` counts PDF pages locally, stores each page group privately, and dispatches one `ProcessDocumentBatch` queue job per missing group. Each worker job makes its own Gemini request, validates its absolute page range, retries up to three times, and stores a successful result under `document-scans/{id}/extraction-batches/`. A later document retry queues only missing or invalid groups. After every group succeeds, `FinalizeDocumentExtraction` merges them in source order and validates the complete result before `StoreDocumentFindings` inserts one original observation record and sets extraction status to `completed` in the same database transaction. Images and one-page PDFs use one request. The service refuses a second write, and the database permits only one findings record per scan. Extraction status is separate from Phase 1 `status`; failed extraction leaves the basic summary intact. No existing scan is backfilled with findings. Future human corrections should use separate records linked to the original; editing `observations` would erase what the extractor first reported.

`AI_SCAN_PAGES_PER_BATCH=2` controls the page-group size. Queue worker count controls concurrency: run `npm run workers` (`npm.cmd run workers` in Windows PowerShell) to start five `php artisan queue:work` processes for local development, allowing five page groups to call Gemini at once. Stop any existing queue worker first to keep the total at five. After changing environment configuration, clear cached configuration when applicable and run `php artisan queue:restart` so long-running workers load it. The coordinator timeout is 3600 seconds, so the database queue `DB_QUEUE_RETRY_AFTER` must remain greater than 3600 (default 3660). For other queue drivers, set their retry visibility above the job timeout. Compressed PDFs unsupported by FPDI use local Python 3 and `pypdf` (`python -m pip install -r requirements.txt` during setup). If neither parser can split a PDF, extraction fails while preserving its private original and any earlier group results. Workers on separate hosts require a shared queue database and a shared `local` storage disk for the private page-group files.

Findings are stored in the application database and are reachable through the existing scan relation. No public findings route or file copy is created. Callers must use the scan's existing authorization policy before exposing findings.

## Nodes

- `sections`: stable `id`, original `heading`, nullable `parent_id`, `page_numbers`, and document `order`. A section can span pages; an unstructured document can have none.
- `entities`: stable `id`, `type` (`person`, `organization`, `other`), extensible `role`, nullable `display_name`, `related_entity_ids`, and `page_numbers`. An unknown field owner uses `entity_id: null`. Ownership is never inferred as Applicant.
- `fields`: stable `id`, original `label`, `type`, nullable `section_id` and `entity_id`, nullable `raw_value`, nullable `normalized_value`, `answer_status`, nullable `selection_mode`, `options`, `evidence`, and nullable `notes`. Types are `text`, `number`, `currency`, `date`, `boolean`, `choice`, `signature`, and `other`. A later type requires a new contract version. Statuses are `answered`, `unanswered`, `ambiguous`, `unreadable`, `conflict`, and `not_applicable`. Null never means false or No.
- `tables`: stable `id`, nullable section/entity IDs, ordered `columns` (`id`, original `heading`), ordered `rows` (`id`, `cells`), and `evidence`. Each cell refers to a column ID and has nullable `raw_value`, nullable `type` and `normalized_value`, nullable `notes`, and optional `evidence`. Typed cell values follow the same decimal, currency, date, and boolean rules as fields. Empty rows or a table with no rows are valid; no record is invented from a blank row.
- `content_blocks`: stable `id`, `type` (`heading`, `paragraph`, `declaration`, `instruction`, `other`), original or concise relevant `text`, nullable section/entity IDs, and `evidence`. Printed declarations stay separate from entered answers.
- `issues`: stable `id`, extensible `type`, `severity` (`info`, `warning`, `error`), `description`, nullable `finding_id` and `page_number`, and `resolution_status` (`open`, `resolved`, `dismissed`). An issue records uncertainty, not a verified accuracy score.

`evidence` requires `page_number` and can include `region_ref`, `text_snippet`, `notes`, and `bbox`. Bounding boxes use normalized page coordinates `{x,y,width,height}` in the range 0–1, measured from the top left; they are optional and may be omitted or null when coordinates are unavailable. Malformed boxes are rejected. Page references are independent of bounding boxes. The validator checks page references and box bounds. A cell or option may omit its own evidence (or use null) when only table or field evidence is available.

## Typed values and selections

Number values are decimal **strings**. Currency values are `{ "amount": "4280.35", "currency": "MYR" }`, with a decimal string and three-letter uppercase currency code; floating-point amounts are rejected. Dates use real calendar dates in `YYYY-MM-DD` form. Boolean values are actual JSON booleans. Signature values are `present`, `absent`, `ambiguous`, or `unreadable`; presence does not authenticate a signer. A choice's normalized value is an ordered list of selected option IDs, or null when no confident selection is available.

Choice fields require `selection_mode: "single"` or `"multiple"`. Every printed option remains in `options`, including unmarked ones, with stable `id`, original `label`, `mark_state` (`marked`, `unmarked`, `ambiguous`, `unreadable`), nullable `free_text`, and optional `evidence`. A blank single-choice group has all marks `unmarked`, `answer_status: "unanswered"`, and `normalized_value: null`. An explicit No is a marked No option, not a null value. Multiple marked options in a single-choice group must be retained with `answer_status: "conflict"` and a `conflicting_selection` issue referring to that field. The validator never chooses one silently.

## Example

```json
{
  "schema_version": "1.0",
  "document": {"reference": "42", "page_count": 1, "reading_status": "complete", "limitations": null},
  "pages": [{"number": 1, "reading_status": "complete", "section_ids": [], "issues": []}],
  "sections": [],
  "entities": [{"id": "holder", "type": "person", "role": "Holder", "display_name": "Amina Noor", "related_entity_ids": [], "page_numbers": [1]}],
  "fields": [{"id": "birth-date", "label": "Date of birth", "type": "date", "section_id": null, "entity_id": "holder", "raw_value": "02 APR 1990", "normalized_value": "1990-04-02", "answer_status": "answered", "selection_mode": null, "options": [], "evidence": {"page_number": 1}, "notes": null}],
  "tables": [],
  "content_blocks": [],
  "issues": []
}
```

The example reference must match an existing scan ID when validated. Synthetic identity, payslip, bank statement, application, conflicting form, and contract fixtures are in `tests/Fixtures/DocumentFindingsFixtures.php`.

## Validation and Phase 3 boundary

`DocumentFindingsV1::validate($scan, $payload)` rejects missing structure, invalid types and statuses, duplicate finding IDs, invalid references, out-of-range pages, malformed typed values, invalid table columns, and unflagged single-choice conflicts. Valid uncertainty remains intact. `StoreDocumentFindings::store()` is the only final write path. Each batch is checked against the locally counted PDF pages, then the merged V1 payload is checked again. Phase 4 visual choice verification runs against the same batch PDF before its result is saved.
