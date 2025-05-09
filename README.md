# 🚀 OpenAI Service for Laravel (`encurio/openai-service`)

This package provides a flexible **OpenAI Service** for Laravel, enabling seamless communication with OpenAI’s APIs:

* **Assistants** (legacy threads & new Tools API)
* **Chat Completions**
* **Embeddings**
* **Moderations**
* **Images**

---

## 📌 Features

* ✅ **Assistants & Completions** — Use structured AI assistants or direct chat completions.
* ✅ **Embeddings & Moderations** — Generate vector embeddings and perform content moderation.
* ✅ **Tool Integrations** — Extend assistant sessions with custom tools and handlers.
* ✅ **Dynamic API Key Handling** — Default keys from `.env` and per-request overrides.
* ✅ **Flexible Model Selection** — Default `gpt-4o`, configurable to any OpenAI model.
* ✅ **Image Support** — Send images (URLs or base64) alongside text.

---

## 📦 Installation

```bash
composer require encurio/openai-service
```

For the latest unreleased features:

```bash
composer require encurio/openai-service:dev-main
```

---

## ⚙️ Configuration

1. **.env**

   ```dotenv
   OPENAI_API_KEY_COMPLETIONS=your_completions_key
   OPENAI_API_KEY_ASSISTANTS=your_assistants_key
   ```

2. **(Optional) Publish config**

   ```bash
   php artisan vendor:publish --tag=openai-config
   ```

   Then edit `config/openai.php`:

   ```php
   return [
       'keys' => [
           'completions' => env('OPENAI_API_KEY_COMPLETIONS'),
           'assistants'  => env('OPENAI_API_KEY_ASSISTANTS'),
       ],
       'retries' => env('OPENAI_RETRIES', 3),
       'timeout' => 60,
   ];
   ```

3. **Service Provider & Facade** (if not auto-discovered)

   In `config/app.php`:

   ```php
   'providers' => [
       // ...
       Encurio\OpenAIService\Providers\OpenAIServiceProvider::class,
   ],
   'aliases' => [
       'OpenAI' => Encurio\OpenAIService\Facades\OpenAI::class,
   ],
   ```

4. **Clear caches**

   ```bash
   php artisan config:clear && php artisan cache:clear
   ```

---

## 🛠️ Usage Examples

### 1️⃣ Chat Completion

```php
use OpenAI;

$response = OpenAI::completion([
    'messages'   => [
        ['role' => 'user', 'content' => 'Write a haiku about Laravel.'],
    ],
    'model'       => 'gpt-3.5-turbo',
    'temperature' => 0.5,
    'max_tokens'  => 60,
]);
echo $response['choices'][0]['message']['content'];
```

### 2️⃣ Assistant (Legacy Threads, **without** tools)

```php
use OpenAI;

$response = OpenAI::assistant([
    'assistant_id' => 'asst_123456789',
    'messages'     => [
        ['role' => 'system', 'content' => 'You are a helpful assistant.'],
        ['role' => 'user',   'content' => 'Summarize our Q1 financials.'],
    ],
    'model'        => 'gpt-4o',
    'temperature'  => 0.7,
    'max_tokens'   => 500,
    'top_p'        => 1.0,
]);
echo $response['choices'][0]['message']['content'];
```

### 3️⃣ Assistant **with Tools** (new API)

```php
use OpenAI;

// Define tools
$tools = [
    [
        'name'        => 'web_search',
        'description' => 'Search the internet',
        'parameters'  => [
            'query' => ['type' => 'string', 'description' => 'Search term'],
        ],
    ],
];
// Define handlers
$handlers = [
    'web_search' => function(array $args) {
        return ['results' => MySearchService::search($args['query'])];
    },
];

// Call assistant with tools
$response = OpenAI::assistant([
    'assistant_id'  => 'asst_123456789',
    'messages'      => [
        ['role' => 'system', 'content' => 'Use tools to fetch data.'],
        ['role' => 'user',   'content' => 'Search for current Berlin weather.'],
    ],
    'tools'         => $tools,
    'tool_handlers' => $handlers,
    'model'         => 'gpt-4o',
    'temperature'   => 0.5,
]);
$reply = end($response);
echo $reply['content'];
```

### 4️⃣ Embeddings

```php
$response = OpenAI::requestOpenAI([
    'type'  => 'embedding',
    'model' => 'text-embedding-ada-002',
    'input' => ['This is some text to embed'],
]);
print_r($response['data']);
```

### 5️⃣ Moderation

```php
$response = OpenAI::requestOpenAI([
    'type'  => 'moderation',
    'input' => 'Some user-generated content',
]);
print_r($response['results']);
```

### 6️⃣ Image Analysis

```php
$response = OpenAI::requestOpenAI([
    'type'    => 'completion',
    'model'   => 'gpt-4o',
    'messages'=> [
        ['role'=>'user','content'=>[
            ['type'=>'text','text'=>'Describe this image'],
            ['type'=>'image_url','image_url'=>[
                'url'=>'https://example.com/pic.jpg',
                'detail'=>'high',
            ]],
        ]],
    ],
]);
echo $response['choices'][0]['message']['content'];
```

---

## 🔧 Available Parameters

| Key             | Type         | Default    | Description                                                |
| --------------- | ------------ | ---------- | ---------------------------------------------------------- |
| `type`          | string       | completion | `completion`, `assistant`, `embedding`, `moderation`, etc. |
| `messages`      | array        | required   | Chat messages (\[role,content] pairs)                      |
| `assistant_id`  | string       | —          | Assistant ID (for `assistant` type)                        |
| `model`         | string       | gpt-4o     | Model name (`gpt-4o`, `gpt-3.5-turbo`, etc.)               |
| `temperature`   | float        | 0.7        | Sampling temperature                                       |
| `max_tokens`    | int          | 1000       | Max tokens                                                 |
| `top_p`         | float        | 1.0        | Nucleus sampling parameter                                 |
| `tools`         | array        | \[]        | Tools definitions (for assistant sessions)                 |
| `tool_handlers` | array        | \[]        | Handler callbacks for tools                                |
| `input`         | array/string | —          | Input for `embedding` or `moderation` calls                |
| `api_key`       | string       | .env value | Override default API key                                   |
| `retries`       | int          | 3          | Retry attempts                                             |

---

## 📄 License

MIT
