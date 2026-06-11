# OpenAI Service for Laravel (`encurio/openai-service`)

A Laravel package for working with OpenAI from application services.

The **Responses API** is now the recommended default for new implementations. Legacy **Chat Completions** and **Assistants v2 Threads/Runs** remain available for backward compatibility, but new projects should not build around Assistants/Threads.

## Features

* Responses API support for text, structured output, tools, conversation references, and model calls.
* Conversation helper methods for the current OpenAI conversation model.
* Legacy Chat Completions support.
* Legacy Assistants v2 Threads/Runs support, marked as deprecated in code.
* Embeddings, Moderations, Images, and Files helpers.
* Per-request API key override through `setProjectApiKey()` or request options.

## Installation

```bash
composer require encurio/openai-service
```

## Configuration

Publish the config file when you want to customize defaults:

```bash
php artisan vendor:publish --tag=openai-config
```

Recommended `.env` configuration:

```env
OPENAI_API_KEY=your_openai_project_key
OPENAI_DEFAULT_MODEL=gpt-4.1-mini
OPENAI_EMBEDDING_MODEL=text-embedding-3-small
OPENAI_IMAGE_MODEL=gpt-image-1
OPENAI_RETRIES=3
OPENAI_TIMEOUT=60
```

Backward-compatible keys still work, but should not be used for new projects:

```env
OPENAI_API_KEY_COMPLETIONS=legacy_key
OPENAI_API_KEY_ASSISTANTS=legacy_key
```

## Current recommended usage: Responses API

```php
use Encurio\OpenAIService\Facades\OpenAI;

$response = OpenAI::response([
    'model' => 'gpt-4.1-mini',
    'input' => 'Write a short SEO-friendly product description for a reusable coffee filter.',
    'instructions' => 'Write concise, factual ecommerce copy.',
    'max_output_tokens' => 300,
]);

print_r($response);
```

## Structured output

```php
use Encurio\OpenAIService\Facades\OpenAI;

$response = OpenAI::response([
    'model' => 'gpt-4.1-mini',
    'input' => 'Extract the product name, material, and key benefit from: Stainless steel reusable coffee filter, dishwasher-safe, reduces paper waste.',
    'text' => [
        'format' => [
            'type' => 'json_schema',
            'name' => 'product_summary',
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'product_name' => ['type' => 'string'],
                    'material' => ['type' => 'string'],
                    'key_benefit' => ['type' => 'string'],
                ],
                'required' => ['product_name', 'material', 'key_benefit'],
            ],
        ],
    ],
]);

print_r($response);
```

## Conversations

```php
use Encurio\OpenAIService\Facades\OpenAI;

$conversationId = OpenAI::createConversation();

OpenAI::addConversationItem($conversationId, [
    'type' => 'message',
    'role' => 'user',
    'content' => [
        ['type' => 'input_text', 'text' => 'Remember that this customer prefers German invoices.'],
    ],
]);

$response = OpenAI::response([
    'model' => 'gpt-4.1-mini',
    'conversation' => $conversationId,
    'input' => 'Create the next customer support reply.',
]);
```

## Images

```php
use Encurio\OpenAIService\Facades\OpenAI;

$response = OpenAI::image([
    'prompt' => 'A clean ecommerce product photo of a reusable stainless steel coffee filter on a white background.',
    'model' => 'gpt-image-1',
    'size' => '1024x1024',
    'quality' => 'high',
    'output_format' => 'png',
]);

print_r($response);
```

For GPT image models, the package no longer sends `response_format=url` by default. Pass `response_format` only when you intentionally use an older image model that supports it.

## Embeddings

```php
use Encurio\OpenAIService\Facades\OpenAI;

$response = OpenAI::requestOpenAI([
    'type' => 'embedding',
    'model' => 'text-embedding-3-small',
    'input' => ['Your text to embed here'],
]);

print_r($response['data']);
```

## Moderations

```php
use Encurio\OpenAIService\Facades\OpenAI;

$response = OpenAI::requestOpenAI([
    'type' => 'moderation',
    'input' => 'Text to classify for policy violations',
]);

print_r($response['results']);
```

## Legacy Chat Completions

`completion()` and `requestOpenAI(['type' => 'completion', ...])` remain available for existing projects.

```php
use Encurio\OpenAIService\Facades\OpenAI;

$response = OpenAI::completion([
    'model' => 'gpt-4.1-mini',
    'messages' => [
        ['role' => 'user', 'content' => 'Tell me a joke.'],
    ],
    'temperature' => 0.9,
    'max_tokens' => 200,
]);

print_r($response);
```

## Legacy Assistants v2 Threads/Runs

These methods remain in the package for backward compatibility, but are deprecated in code:

* `createThread()`
* `appendMessageToThread()`
* `startRun()`
* `pollUntilRunComplete()`
* `pollAndSubmitToolCalls()`
* `getThreadMessages()`
* `runThread()`

Use `response()`, `createConversation()`, `addConversationItem()`, and `listConversationItems()` for new implementations.

## Error handling

New Responses API methods throw `OpenAIRequestException` on HTTP or API failure. The exception exposes:

```php
$exception->context();
$exception->status();
$exception->responseBody();
$exception->requestId();
```

Legacy `requestOpenAI()` keeps nullable failure behavior for backward compatibility.

## License

This package is licensed under the MIT License.
