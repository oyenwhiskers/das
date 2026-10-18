<?php

return [
    'chunk_size' => (int) env('BIMBSEC_CHUNK_SIZE', 8),
    'max_selection' => (int) env('BIMBSEC_MAX_SELECTION', 40),
];
