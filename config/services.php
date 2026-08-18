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

    'project_facts' => [
        'extraction_chunk_batch_size' => (int) env('PROJECT_FACT_EXTRACTION_CHUNK_BATCH_SIZE', 4),
        'extraction_max_batch_characters' => (int) env('PROJECT_FACT_EXTRACTION_MAX_BATCH_CHARACTERS', 6000),
        'extraction_max_facts' => (int) env('PROJECT_FACT_EXTRACTION_MAX_FACTS', 40),
        'extraction_min_confidence' => (float) env('PROJECT_FACT_EXTRACTION_MIN_CONFIDENCE', 0.7),
        'extraction_max_output_tokens' => (int) env('PROJECT_FACT_EXTRACTION_MAX_OUTPUT_TOKENS', 4096),
        'extraction_timeout' => (int) env('PROJECT_FACT_EXTRACTION_TIMEOUT', 60),
    ],

    'grounded_context' => [
        'min_fact_score' => (float) env('GROUNDED_CONTEXT_MIN_FACT_SCORE', 0.2),
        'strong_fact_score' => (float) env('GROUNDED_CONTEXT_STRONG_FACT_SCORE', 0.4),
        'min_document_score' => (float) env('GROUNDED_CONTEXT_MIN_DOCUMENT_SCORE', 0.3),
        'strong_document_score' => (float) env('GROUNDED_CONTEXT_STRONG_DOCUMENT_SCORE', 0.6),
        'document_top_k' => (int) env('GROUNDED_CONTEXT_DOCUMENT_TOP_K', 10),
        'max_facts' => (int) env('GROUNDED_CONTEXT_MAX_FACTS', 20),
        'max_documents' => (int) env('GROUNDED_CONTEXT_MAX_DOCUMENTS', 10),
    ],

];
