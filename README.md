# OpenAI Service for Laravel

A flexible and scalable integration for OpenAI in Laravel, supporting both chat completions and OpenAI assistants.

## Installation

```bash
composer require encurio/openai-service
```

## Usage

```php
use Encurio\OpenAIService\Facades\OpenAI;

$response = OpenAI::requestOpenAI([
    ['role' => 'user', 'content' => 'Tell me a joke.']
]);

echo $response['choices'][0]['message']['content'];
```
