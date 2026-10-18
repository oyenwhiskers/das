<?php

return [
    'max_upload_kb' => (int) env('DOCUMENT_SCAN_MAX_KB', 10240),
    'pages_per_batch' => max(1, (int) env('AI_SCAN_PAGES_PER_BATCH', 2)),
    // shortcut: 0 keeps every PDF on page groups; raise it only after a real-document trial, not from mocked tests.
    'whole_document_max_pages' => max(0, (int) env('AI_SCAN_WHOLE_DOCUMENT_MAX_PAGES', 0)),
    'whole_document_max_bytes' => max(0, (int) env('AI_SCAN_WHOLE_DOCUMENT_MAX_BYTES', 8000000)),
];
