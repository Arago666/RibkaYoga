<?php

return [
    'max_bytes' => (int) env('AUDIO_MAX_BYTES', 104857600),
    'max_seconds' => (int) env('AUDIO_MAX_SECONDS', 7200),
    'max_library_bytes' => (int) env('AUDIO_LIBRARY_MAX_BYTES', 1073741824),
    'ffprobe' => env('AUDIO_FFPROBE_BINARY', 'ffprobe'),
    'ffmpeg' => env('AUDIO_FFMPEG_BINARY', 'ffmpeg'),
    'decode_timeout' => (int) env('AUDIO_DECODE_TIMEOUT', 180),
];
