<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'ai_service' => [
        'url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),
        'token' => env('AI_SERVICE_TOKEN'),
        'timeout' => env('AI_SERVICE_TIMEOUT', 60),
        'connect_timeout' => env('AI_SERVICE_CONNECT_TIMEOUT', 5),
    ],

    'knowledge' => [
        'max_file_size_kb' => env('KNOWLEDGE_MAX_FILE_SIZE_KB', 10240),
        'default_top_k' => env('KNOWLEDGE_DEFAULT_TOP_K', 5),
        'max_top_k' => env('KNOWLEDGE_MAX_TOP_K', 20),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4.1-mini'),
        'timeout' => env('OPENAI_TIMEOUT', 30),
        'connect_timeout' => env('OPENAI_CONNECT_TIMEOUT', 5),
        'max_output_tokens' => env('OPENAI_MAX_OUTPUT_TOKENS', 800),
        'pricing' => [
            'gpt-4.1-mini' => [
                'input' => 0.0004,
                'output' => 0.0016,
            ],
            'gpt-4.1' => [
                'input' => 0.002,
                'output' => 0.008,
            ],
            'default' => [
                'input' => 0.001,
                'output' => 0.003,
            ],
        ],
    ],

];
