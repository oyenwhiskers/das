<?php

return [
    'chunk_size' => (int) env('BIMBSEC_CHUNK_SIZE', 8),
    // 0 = no cap; select-all runs through the progress modal in chunk_size steps.
    'max_selection' => (int) env('BIMBSEC_MAX_SELECTION', 0),
];
