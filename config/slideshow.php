<?php

return [
    // Absolute path to the Python analyzer package (run via `uv run`)
    'analyzer_dir' => env('ANALYZER_DIR', base_path('analyzer')),

    'ffmpeg' => env('FFMPEG_BIN', 'ffmpeg'),
    'ffprobe' => env('FFPROBE_BIN', 'ffprobe'),

    'anthropic_api_key' => env('ANTHROPIC_API_KEY'),

    // Rendering targets
    'outputs' => [
        'landscape' => ['width' => 1920, 'height' => 1080],
        'vertical' => ['width' => 1080, 'height' => 1920],
    ],

    // Proxy resolution used for analysis
    'proxy_height' => 480,
];
