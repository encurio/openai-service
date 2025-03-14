<?php

declare(strict_types=1);

namespace Encurio\OpenAIService\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class OpenAIService
{
    private string $apiKeyCompletions;
    private string $apiKeyAssistants;

    private string $baseUrlCompletions;
    private string $baseUrlAssistants;
    private int $timeout;

    public function __construct()
    {
        $this->baseUrlCompletions = 'https://api.openai.com/v1/chat/completions';
        $this->baseUrlAssistants = 'https://api.openai.com/v1/assistants';
        $this->timeout = 60;

        // ✅ Load API keys from config/services.php
        $this->apiKeyCompletions = config('services.openai.api_key_completions', '');
        $this->apiKeyAssistants = config('services.openai.api_key_assistants', '');
    }

    public function requestOpenAI(
        array $messages,
        bool $useAssistant = false,
        ?string $assistantId = null,
        ?string $model = null,
        ?float $temperature = 0.7,
        ?int $maxTokens = 1000,
        ?float $topP = 1.0,
        ?string $apiKey = null,
        int $retries = 3
    ): ?array {
        // ✅ Select the correct API key
        $apiKey = $apiKey
            ?? ($useAssistant ? $this->apiKeyAssistants : $this->apiKeyCompletions);

        // 🔥 Ensure API key exists before making the request
        if (empty($apiKey)) {
            throw new \Exception("❌ Missing OpenAI API Key. Set it in .env or pass it explicitly.");
        }

        $url = $useAssistant
            ? "{$this->baseUrlAssistants}/$assistantId/threads"
            : $this->baseUrlCompletions;

        $payload = [
            'messages' => $messages,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'top_p' => $topP,
        ];

        if (!$useAssistant || $model) {
            $payload['model'] = $model ?? 'gpt-4o-mini';
        }

        return $this->sendRequest($apiKey, $url, $payload, $retries);
    }

    private function sendRequest(string $apiKey, string $url, array $payload, int $retries): ?array
    {
        for ($i = 0; $i < $retries; $i++) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Authorization' => "Bearer $apiKey",
                        'Content-Type' => 'application/json',
                    ])
                    ->post($url, $payload);

                if ($response->successful()) {
                    return $response->json();
                }

                Log::error("❌ OpenAI API Error", ['response' => $response->body()]);
            } catch (Exception $e) {
                Log::error("⚠️ OpenAI API Timeout/Error", ['message' => $e->getMessage()]);
                sleep(2);
            }
        }

        return null;
    }
}
