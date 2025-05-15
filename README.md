# 🚀 OpenAI Service for Laravel (`encurio/openai-service`)

This package provides a flexible **OpenAI Service** for Laravel, allowing seamless integration with OpenAI’s API.
Supports **Assistants**, **Completions**, **Embeddings**, **Moderations**, **Images**, and **Tool Integrations**.

---

## 📌 Features

* ✅ **Assistants & Completions** – Structured AI assistants or direct text completions.
* ✅ **Embeddings & Moderations** – Generate vector embeddings and perform content moderation.
* ✅ **Dynamic API Key Handling** – Uses default keys from `.env` but allows per-request overrides.
* ✅ **Flexible Model Selection** – Defaults to `gpt-4o`, but any OpenAI model can be used.
* ✅ **Handles Images & Text** – Send both textual prompts and images for analysis.
* ✅ **Tool Integration** – Pass arrays of `tools` and `toolHandlers` to extend assistant capabilities.

---

## 📦 Installation

```bash
composer require encurio/openai-service
```

---

## ⚙️ Configuration

### 1️⃣ Set OpenAI API Keys in `.env`

```env
OPENAI_API_KEY_COMPLETIONS=your_openai_completions_key
OPENAI_API_KEY_ASSISTANTS=your_openai_assistants_key
```

### 2️⃣ Publish Config File (Optional)

```bash
php artisan vendor:publish --tag=openai-config
```

This will generate `config/openai.php`:

````php
<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | OpenAI API Keys
    |--------------------------------------------------------------------------
    | Define your API keys for completions and assistants.
    */
    'keys' => [
        'completions' => env('OPENAI_API_KEY_COMPLETIONS', ''),
        'assistants'  => env('OPENAI_API_KEY_ASSISTANTS', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Request Settings
    |--------------------------------------------------------------------------
    | Retries and timeout for API calls.
    */
    'retries' => env('OPENAI_RETRIES', 3),
    'timeout' => env('OPENAI_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Endpoints
    |--------------------------------------------------------------------------
    | Override base URLs if needed.
    */
    'endpoints' => [
        'completions' => env(
            'OPENAI_URL_COMPLETIONS',
            'https://api.openai.com/v1/chat/completions'
        ),
        'threads'     => env(
            'OPENAI_URL_THREADS',
            'https://api.openai.com/v1/threads'
        ),
        'embeddings'  => env(
            'OPENAI_URL_EMBEDDINGS',
            'https://api.openai.com/v1/embeddings'
        ),
        'moderations' => env(
            'OPENAI_URL_MODERATIONS',
            'https://api.openai.com/v1/moderations'
        ),
        'images'      => env(
            'OPENAI_URL_IMAGES',
            'https://api.openai.com/v1/images/generations'
        ),
    ],
];
```bash
php artisan vendor:publish --tag=openai-config
````

> This will generate the `config/openai.php` file in your project, which you can customize:

```php
<?php

declare(strict_types=1);

return [
    /**
     * Default OpenAI API keys for various features
     */
    'keys' => [
        'completions' => env('OPENAI_API_KEY_COMPLETIONS'),
        'assistants'  => env('OPENAI_API_KEY_ASSISTANTS'),
    ],

    /**
     * Number of automatic retry attempts on failure
     */
    'retries' => env('OPENAI_RETRIES', 3),
];
```

---

## 🛠️ Usage Examples

### 1️⃣ Basic Completion Request (Text Generation)

```php
use Encurio\OpenAIService\Facades\OpenAI;

$response = OpenAI::requestOpenAI([
    'type' => 'completion',
    'model' => 'gpt-4o',
    'messages' => [
        ['role' => 'user', 'content' => 'Write a short SEO-friendly product description.']
    ],
]);
echo $response['choices'][0]['message']['content'];

// Or using the completion shortcut:
$response = OpenAI::completion([
    'messages' => [
        ['role' => 'user', 'content' => 'Tell me a joke.']
    ],
    'model' => 'gpt-3.5-turbo',
    'temperature' => 0.9,
    'maxTokens' => 200
]);
```

### 2️⃣ Using Conversation Threads

Ein Beispiel, wie man mit Konversations-Threads interagiert, statt die `assistant()`-Methode direkt zu nutzen:

```php
use Encurio\OpenAIService\Facades\OpenAI;

// 1. Neuen Thread anlegen
$threadId = OpenAI::createThread();

// 2. Benutzer-Nachricht hinzufügen
OpenAI::appendMessageToThread($threadId, [
    ['role' => 'user', 'content' => 'Can you calculate 14 * 7?']
]);

// 3. Assistant-Run starten mit Tools
$runId = OpenAI::startRun(
    threadId:    $threadId,
    assistantId: 'asst_123456789',
    model:       'gpt-4o',
    tools:       ['calculator'],
    toolHandlers:[
        'calculator' => fn($input) => CalculatorService::calculate($input),
    ]
);

// 4. Warten bis der Run abgeschlossen ist
OpenAI::pollUntilRunComplete(threadId: $threadId, runId: $runId);

// 4b. Polling und automatische Tool-Ausführung
$runResult = OpenAI::pollAndSubmitToolCalls(
    threadId:    $threadId,
    runId:       $runId,
    toolHandlers:[
        'calculator' => fn($input) => CalculatorService::calculate($input),
    ]
);

// 5. Alle Nachrichten aus dem Run abrufen
$messages = $runResult['messages'];
($threadId);

// 6. Antwort des Assistants ausgeben
$assistantMsg = collect($messages)->firstWhere('role', 'assistant');
echo $assistantMsg['content'];
```

### 3️⃣ Generating Embeddings Generating Embeddings

```php
use Encurio\OpenAIService\Facades\OpenAI;

$response = OpenAI::requestOpenAI([
    'type' => 'embedding',
    'model' => 'text-embedding-ada-002',
    'input' => ['Your text to embed here'],
]);

print_r($response['data']);
```

### 4️⃣ Content Moderation

```php
use Encurio\OpenAIService\Facades\OpenAI;

$response = OpenAI::requestOpenAI([
    'type' => 'moderation',
    'input' => 'Text to classify for policy violations',
]);

print_r($response['results']);
```

### 5️⃣ Sending an Image for Analysis

```php
use Encurio\OpenAIService\Facades\OpenAI;

$imageUrl = "https://example.com/sample.jpg";

$response = OpenAI::requestOpenAI([
    'type' => 'completion', // or 'assistant'
    'model' => 'gpt-4o',
    'messages' => [
        ['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'Describe this image.'],
            ['type' => 'image_url', 'image_url' => [
                'url' => $imageUrl,
                'detail' => 'high'
            ]]
        ]]
    ],
]);

echo $response['choices'][0]['message']['content'];
```

---

## 🔧 Available Parameters

| Parameter      | Type     | Default      | Description                                                  |
| -------------- | -------- | ------------ | ------------------------------------------------------------ |
| `type`         | `string` | `completion` | One of `completion`, `assistant`, `embedding`, `moderation`. |
| `messages`     | `array`  | Required     | List of chat messages (`system`, `user`, `assistant`).       |
| `tools`        | `array`  | `[]`         | Names of tools to enable in an assistant session.            |
| `toolHandlers` | `array`  | `[]`         | Associative array of tool names to handler callbacks.        |
| `assistantId`  | `string` | `null`       | The ID of an OpenAI Assistant (if `type` is `assistant`).    |
| `model`        | `string` | `gpt-4o`     | The OpenAI model to use (e.g., `gpt-4o`, `gpt-3.5-turbo`).   |
| `temperature`  | `float`  | `0.7`        | Controls randomness (`0.0` = strict, `1.0` = creative).      |
| `maxTokens`    | `int`    | `1000`       | The response length limit in tokens.                         |
| `topP`         | `float`  | `1.0`        | Sampling parameter (`0.0`–`1.0`).                            |
| `apiKey`       | `string` | `.env value` | Overrides the default API key.                               |
| `retries`      | `int`    | `3`          | Number of retry attempts on failure.                         |

---

---

## 📄 License

This package is licensed under the MIT License.
