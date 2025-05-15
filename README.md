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

```bash\ nphp artisan vendor:publish --tag=openai-config
```

This will generate `config/openai.php`, where you can set defaults, including rate limiting:

```php
return [
    'keys' => [
        'completions' => env('OPENAI_API_KEY_COMPLETIONS'),
        'assistants'  => env('OPENAI_API_KEY_ASSISTANTS'),
    ],

    // Default retry behavior
    'retries' => env('OPENAI_RETRIES', 3),
];
```

---

## 🛠️ Usage Examples

### 1️⃣ Basic Completion Request (Text Generation)

```php
use OpenAI;

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

### 2️⃣ Using an OpenAI Assistant (with Tools)

```php
use OpenAI;

$messages = [
    ['role' => 'system', 'content' => 'You are a calculator.'],
    ['role' => 'user', 'content' => 'What is 14 * 7?']
];

$tools = ['calculator'];
$toolHandlers = [
    'calculator' => fn($input) => CalculatorService::calculate($input)
];

$response = OpenAI::assistant(
    assistantId: 'asst_123456789',
    messages: $messages,
    tools: $tools,
    toolHandlers: $toolHandlers,
    model: 'gpt-4o'
);
echo $response['choices'][0]['message']['content'];
```

### 3️⃣ Generating Embeddings

```php
use OpenAI;

$response = OpenAI::requestOpenAI([
    'type' => 'embedding',
    'model' => 'text-embedding-ada-002',
    'input' => ['Your text to embed here'],
]);

print_r($response['data']);
```

### 4️⃣ Content Moderation

```php
use OpenAI;

$response = OpenAI::requestOpenAI([
    'type' => 'moderation',
    'input' => 'Text to classify for policy violations',
]);

print_r($response['results']);
```

### 5️⃣ Sending an Image for Analysis

```php
use OpenAI;

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
