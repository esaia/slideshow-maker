<?php

return [
    // Absolute path to the Python analyzer package (run via `uv run`)
    'analyzer_dir' => env('ANALYZER_DIR', base_path('analyzer')),

    'ffmpeg' => env('FFMPEG_BIN', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_BIN', 'ffprobe'),

    // Hardware-accelerated decode/encode (e.g. "videotoolbox" on macOS) —
    // trades a little file size for far less CPU time and heat. Set
    // FFMPEG_HWACCEL="" to force pure software libx264 if it misbehaves.
    'hwaccel' => env('FFMPEG_HWACCEL', PHP_OS_FAMILY === 'Darwin' ? 'videotoolbox' : null),

    'anthropic_api_key' => env('ANTHROPIC_API_KEY'),

    // Rendering targets
    'outputs' => [
        'landscape' => ['width' => 1920, 'height' => 1080],
        'vertical' => ['width' => 1080, 'height' => 1920],
    ],

    // Proxy resolution used for analysis
    'proxy_height' => 480,
];
