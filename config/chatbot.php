<?php

return [
    'runtime' => [
        'driver' => env('CHATBOT_RUNTIME_DRIVER', 'n8n'),
        'base_url' => env('CHATBOT_RUNTIME_URL'),
        'secret' => env('CHATBOT_RUNTIME_SECRET'),
        'default_history_limit' => (int) env('CHATBOT_RUNTIME_HISTORY_LIMIT', 20),
    ],

    'n8n' => [
        'ingest_secret' => env('N8N_INGEST_SECRET'),
    ],

    'rag' => [
        'search_limit' => (int) env('CHATBOT_RAG_SEARCH_LIMIT', 8),
        'max_chunk_length' => (int) env('CHATBOT_RAG_MAX_CHUNK_LENGTH', 8000),
    ],

    'chat_upload' => [
        'mirror_to_drive' => filter_var(env('CHATBOT_CHAT_UPLOAD_MIRROR_TO_DRIVE', false), FILTER_VALIDATE_BOOL),
        'mirror_to_drive_required' => filter_var(env('CHATBOT_CHAT_UPLOAD_MIRROR_TO_DRIVE_REQUIRED', false), FILTER_VALIDATE_BOOL),
        'drive_name_prefix' => env('CHATBOT_CHAT_UPLOAD_DRIVE_NAME_PREFIX', 'chat-upload'),
    ],
];
