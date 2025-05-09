<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OpenAI API Keys
    |--------------------------------------------------------------------------
    |
    | Legt deine API-Keys für Chat-Completions und Assistants fest.
    |
    */
    'keys' => [
        'completions' => env('OPENAI_API_KEY_COMPLETIONS', ''),
        'assistants'  => env('OPENAI_API_KEY_ASSISTANTS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Einstellungen
    |--------------------------------------------------------------------------
    |
    | Hier kannst du Standard-Retries und Timeout festlegen.
    |
    */
    'retries' => env('OPENAI_RETRIES', 3),
    'timeout' => env('OPENAI_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Basis-URLs (falls du sie überschreiben willst)
    |--------------------------------------------------------------------------
    */

    'endpoints' => [
        'completions' => env(
            'OPENAI_URL_COMPLETIONS',
            'https://api.openai.com/v1/chat/completions'
        ),

        // the new base for *all* thread operations
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
    ],


];
