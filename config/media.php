<?php

return [
    'storage_limit_bytes' => (int) env('MEDIA_STORAGE_LIMIT_BYTES', 4294967296),
    'max_upload_bytes' => (int) env('MEDIA_MAX_UPLOAD_BYTES', 2147483648),
    'max_duration_seconds' => (int) env('MEDIA_MAX_DURATION_SECONDS', 600),
    'ytdlp' => env('YTDLP_PATH') ?: storage_path('app/bin/yt-dlp'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : '')),
    'node_binary' => env('MEDIA_NODE_BINARY'),
    'chunk_bytes' => 4 * 1024 * 1024,
    'ffmpeg' => env('FFMPEG_PATH', storage_path('app/bin/ffmpeg'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : ''))),
    'ffprobe' => env('FFPROBE_PATH', storage_path('app/bin/ffprobe'.(PHP_OS_FAMILY === 'Windows' ? '.exe' : ''))),
    'font_directory' => storage_path('app/bin/fonts'),
    'gemini_key' => env('GEMINI_API_KEY', ''),
    'gemini_model' => env('GEMINI_MODEL', 'gemini-3.8-flash'),
    'gemini_tts_model' => env('GEMINI_TTS_MODEL', 'gemini-3.8-flash-tts'),
    'gemini_revision' => env('GEMINI_API_REVISION', '2026-05-20'),
    'provider_timeout' => (int) env('MEDIA_PROVIDER_TIMEOUT', 240),
    'voices' => ['Kore', 'Charon', 'Fenrir', 'Aoede', 'Orus', 'Leda'],
    'separation_command' => env('MEDIA_SEPARATION_COMMAND'),
    'separation_python' => env('MEDIA_SEPARATION_PYTHON', storage_path('app/bin/separator/.venv/Scripts/python.exe')),
    'separation_models' => env('MEDIA_SEPARATION_MODELS', storage_path('app/bin/separator/models')),
];
