<?php

return [
    'upload_max_kb' => (int) env('RIBKA_UPLOAD_MAX_KB', 10240),
    'upload_max_pixels' => (int) env('RIBKA_UPLOAD_MAX_PIXELS', 25000000),
];
