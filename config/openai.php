<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Key
    |--------------------------------------------------------------------------
    |
    | OPENAI_API_KEY is the primary key. The old feature-specific keys remain
    | as fallbacks for projects that already use this package.
    |
    */
    'api_key' => env(
        'OPENAI_API_KEY',
        env('OPENAI_API_KEY_COMPLETIONS', env('OPENAI_API_KEY_ASSISTANTS', ''))
    ),

    'keys' => [
        'completions' => env('OPENAI_API_KEY_COMPLETIONS', env('OPENAI_API_KEY', '')),
        'assistants'  => env('OPENAI_API_KEY_ASSISTANTS', env('OPENAI_API_KEY', '')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Settings
    |--------------------------------------------------------------------------
    */
    'retries' => env('OPENAI_RETRIES', 3),
    'timeout' => env('OPENAI_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        'model' => env('OPENAI_DEFAULT_MODEL', 'gpt-4.1-mini'),
        'reasoning_model' => env('OPENAI_DEFAULT_REASONING_MODEL', 'gpt-4.1-mini'),
        'assistant_model' => env('OPENAI_ASSISTANT_MODEL', env('OPENAI_DEFAULT_MODEL', 'gpt-4.1-mini')),
        'embedding_model' => env('OPENAI_EMBEDDING_MODEL', 'text-embedding-3-small'),
        'image_model' => env('OPENAI_IMAGE_MODEL', 'gpt-image-1'),
        'temperature' => (float) env('OPENAI_TEMPERATURE', 0.7),
        'max_tokens' => (int) env('OPENAI_MAX_TOKENS', 1000),
        'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 1000),
        'top_p' => (float) env('OPENAI_TOP_P', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Endpoints
    |--------------------------------------------------------------------------
    */
    'endpoints' => [
        'responses' => env(
            'OPENAI_URL_RESPONSES',
            'https://api.openai.com/v1/responses'
        ),

        'conversations' => env(
            'OPENAI_URL_CONVERSATIONS',
            'https://api.openai.com/v1/conversations'
        ),

        'completions' => env(
            'OPENAI_URL_COMPLETIONS',
            'https://api.openai.com/v1/chat/completions'
        ),

        'threads' => env(
            'OPENAI_URL_THREADS',
            'https://api.openai.com/v1/threads'
        ),

        'embeddings' => env(
            'OPENAI_URL_EMBEDDINGS',
            'https://api.openai.com/v1/embeddings'
        ),

        'moderations' => env(
            'OPENAI_URL_MODERATIONS',
            'https://api.openai.com/v1/moderations'
        ),

        'images' => env(
            'OPENAI_URL_IMAGES',
            'https://api.openai.com/v1/images/generations'
        ),

        'files' => env(
            'OPENAI_URL_FILES',
            'https://api.openai.com/v1/files'
        ),
    ],
];
