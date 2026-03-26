<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'vision_model' => env('OPENAI_VISION_MODEL', 'gpt-4.1-mini'),
        'transcription_model' => env('OPENAI_TRANSCRIPTION_MODEL', 'whisper-1'),
        'chat_completions_url' => env('OPENAI_CHAT_COMPLETIONS_URL', 'https://api.openai.com/v1/chat/completions'),
        'audio_transcriptions_url' => env('OPENAI_AUDIO_TRANSCRIPTIONS_URL', 'https://api.openai.com/v1/audio/transcriptions'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 120),
    ],

    'google_drive' => [
        'oauth_access_token' => env('GOOGLE_DRIVE_OAUTH_ACCESS_TOKEN'),
        'oauth_client_id' => env('GOOGLE_DRIVE_OAUTH_CLIENT_ID'),
        'oauth_client_secret' => env('GOOGLE_DRIVE_OAUTH_CLIENT_SECRET'),
        'oauth_refresh_token' => env('GOOGLE_DRIVE_OAUTH_REFRESH_TOKEN'),
        'oauth_token_cache_prefix' => env('GOOGLE_DRIVE_OAUTH_TOKEN_CACHE_PREFIX', 'google_drive_oauth_access_token'),
        'service_account_json' => env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON'),
        'service_account_path' => env('GOOGLE_DRIVE_SERVICE_ACCOUNT_PATH'),
        'folder_id' => env('GOOGLE_DRIVE_FOLDER_ID'),
        'scope' => env('GOOGLE_DRIVE_SCOPE', 'https://www.googleapis.com/auth/drive'),
        'token_url' => env('GOOGLE_DRIVE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'files_url' => env('GOOGLE_DRIVE_FILES_URL', 'https://www.googleapis.com/drive/v3/files'),
        'upload_base_url' => env('GOOGLE_DRIVE_UPLOAD_BASE_URL', 'https://www.googleapis.com/upload/drive/v3/files'),
        'timeout' => (int) env('GOOGLE_DRIVE_TIMEOUT', 30),
    ],

    'cnpja' => [
        'api_key' => env('CNPJA_API_KEY'),
        'base_url' => env('CNPJA_BASE_URL', 'https://api.cnpja.com'),
        'public_base_url' => env('CNPJA_PUBLIC_BASE_URL', 'https://open.cnpja.com'),
        'timeout' => (int) env('CNPJA_TIMEOUT', 15),
        'strategy' => env('CNPJA_STRATEGY', 'CACHE_IF_ERROR'),
        'max_age' => (int) env('CNPJA_MAX_AGE', 7),
        'max_stale' => (int) env('CNPJA_MAX_STALE', 30),
        'include_simples' => filter_var(env('CNPJA_INCLUDE_SIMPLES', true), FILTER_VALIDATE_BOOL),
    ],

    'cpf_cnpj' => [
        'api_key' => env('CPF_CNPJ_API_KEY'),
        'base_url' => env('CPF_CNPJ_BASE_URL', 'https://api.cpfcnpj.com.br'),
        'timeout' => (int) env('CPF_CNPJ_TIMEOUT', 60),
    ],

    'viacep' => [
        'base_url' => env('VIACEP_BASE_URL', 'https://viacep.com.br/ws'),
        'timeout' => (int) env('VIACEP_TIMEOUT', 10),
    ],

];
