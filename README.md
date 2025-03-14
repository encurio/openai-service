# 🚀 OpenAI Service for Laravel (`encurio/openai-service`)

This package provides a flexible **OpenAI Service** for Laravel, allowing seamless integration with OpenAI’s API.  
Supports both **Assistants** (structured interactions) and **Completions** (direct text responses).  

---

## 📌 Features
- ✅ **Supports Assistants & Completions** – Structured AI assistants or direct text completions.
- ✅ **Dynamic API Key Handling** – Uses a default key but allows per-request overrides.
- ✅ **Flexible Model Selection** – Defaults to `gpt-4o`, but any OpenAI model can be used.
- ✅ **Handles Images & Text** – Send images for AI-based recognition.
- ✅ **Built-in Rate Limiting & Error Handling** – Prevents API overuse and manages failures.

---

## 📦 Installation
```bash
composer require encurio/openai-service
```

---

## ⚙️ Configuration

### 1️⃣ Set API Key in `.env`
Add your OpenAI API key:
```env
OPENAI_API_KEY=your_openai_api_key
```

### 2️⃣ Publish Config File (Optional)
```bash
php artisan vendor:publish --tag=openai-config
```
This will generate `config/openai.php`, where you can set defaults.

---

## 🛠️ Usage Examples

### 1️⃣ Basic Completion Request (Text Generation)
```php
use OpenAI;

$response = OpenAI::requestOpenAI(
    [['role' => 'user', 'content' => 'Write a short SEO-friendly product description.']],
    useAssistant: false
);

echo $response['choices'][0]['message']['content'];
```

### 2️⃣ Using an OpenAI Assistant
```php
$response = OpenAI::requestOpenAI(
    [['role' => 'user', 'content' => 'Analyze this artwork.']],
    assistantId: 'asst_12345' // Your Assistant ID
);
```

### 3️⃣ Sending an Image for Analysis
```php
$imageUrl = "https://example.com/sample.jpg";

$response = OpenAI::requestOpenAI(
    [['role' => 'user', 'content' => [
        ['type' => 'text', 'text' => 'Describe this artwork in detail.'],
        ['type' => 'image_url', 'image_url' => [
            'url' => $imageUrl,
            'detail' => 'high'
        ]]
    ]]],
    useAssistant: false
);

echo $response['choices'][0]['message']['content'];
```

---

## 🔧 Available Parameters
| Parameter       | Type      | Default      | Description |
|----------------|-----------|--------------|-------------|
| `messages`     | `array`   | Required     | List of chat messages (roles: `system`, `user`, `assistant`). |
| `useAssistant` | `bool`    | `false`      | Whether to use an OpenAI Assistant for this request. |
| `assistantId`  | `string`  | `null`       | The ID of an OpenAI Assistant (if `useAssistant` is `true`). |
| `model`        | `string`  | `'gpt-4o'`   | The OpenAI model to use (e.g., `gpt-4o`, `gpt-3.5-turbo`). |
| `temperature`  | `float`   | `0.7`        | Controls randomness (`0.0` = strict, `1.0` = creative). |
| `maxTokens`    | `int`     | `1000`       | The response length limit in tokens. |
| `topP`         | `float`   | `1.0`        | Alternative to temperature, sampling technique (`0.0`–`1.0`). |
| `apiKey`       | `string`  | `.env value` | Allows overriding the default API key from `.env`. |
| `retries`      | `int`     | `3`          | Number of retry attempts if the request fails. |

---

## ✅ Running Tests
To run unit tests:
```bash
php artisan test
```
Make sure your **OpenAI API Key** is set in **GitHub Secrets** for CI/CD.

---

## 📄 License
This package is licensed under the MIT License.

---
