<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class OpenAIService
{
    public function assistants(
        string $assistantId,
        array $messages,
        array $tools = [],
        array $toolHandlers = [],
        ?string $model = 'gpt-4o'
    ): ?array {
        if (!empty($tools)) {
            return $this->runAssistantWithTools(
                assistantId: $assistantId,
                messages: $messages,
                tools: $tools,
                toolHandlers: $toolHandlers,
                model: $model
            );
        }

        // Legacy fallback
        return $this->requestOpenAI([
            'assistant_id' => $assistantId,
            'messages' => $messages,
            'model' => $model,
            'type' => 'assistant',
        ]);
    }

    public function runAssistantWithTools(
        string $assistantId,
        array $messages,
        array $tools = [],
        array $toolHandlers = [],
        ?string $model = 'gpt-4o'
    ): ?array {
        $threadId = $this->createThread();
        if (!$threadId) return null;

        $this->appendMessageToThread($threadId, $messages);
        $runId = $this->startRun($threadId, $assistantId, $model, $tools);
        if (!$runId) return null;

        $this->processToolCalls($threadId, $runId, $toolHandlers);

        return $this->getThreadMessages($threadId);
    }

    private function createThread(): ?string
    {
        $response = Http::withToken(config('services.openai.secret'))
            ->post('https://api.openai.com/v1/threads', [])->json();

        return $response['id'] ?? null;
    }

    private function appendMessageToThread(string $threadId, array $messages): void
    {
        Http::withToken(config('services.openai.secret'))
            ->post("https://api.openai.com/v1/threads/{$threadId}/messages", [
                'role' => 'user',
                'content' => implode("\n", array_column($messages, 'content')),
            ]);
    }

    private function startRun(string $threadId, string $assistantId, string $model, array $tools): ?string
    {
        $response = Http::withToken(config('services.openai.secret'))
            ->post("https://api.openai.com/v1/threads/{$threadId}/runs", [
                'assistant_id' => $assistantId,
                'model' => $model,
                'tools' => $tools,
                'tool_choice' => 'auto',
                'response_format' => 'json',
            ])->json();

        return $response['id'] ?? null;
    }

    private function processToolCalls(string $threadId, string $runId, array $toolHandlers): void
    {
        do {
            sleep(2);
            $status = Http::withToken(config('services.openai.secret'))
                ->get("https://api.openai.com/v1/threads/{$threadId}/runs/{$runId}")
                ->json();

            if ($status['status'] === 'requires_action' && isset($status['required_action']['submit_tool_outputs'])) {
                $outputs = [];

                foreach ($status['required_action']['submit_tool_outputs']['tool_calls'] as $toolCall) {
                    $name = $toolCall['function']['name'];
                    $args = json_decode($toolCall['function']['arguments'], true);

                    if (isset($toolHandlers[$name]) && is_callable($toolHandlers[$name])) {
                        $outputs[] = [
                            'tool_call_id' => $toolCall['id'],
                            'output' => call_user_func($toolHandlers[$name], $args),
                        ];
                    }
                }

                Http::withToken(config('services.openai.secret'))
                    ->post("https://api.openai.com/v1/threads/{$threadId}/runs/{$runId}/submit_tool_outputs", [
                        'tool_outputs' => $outputs
                    ]);
            }
        } while (in_array($status['status'], ['queued', 'in_progress', 'requires_action']));
    }

    private function getThreadMessages(string $threadId): ?array
    {
        $messages = Http::withToken(config('services.openai.secret'))
            ->get("https://api.openai.com/v1/threads/{$threadId}/messages")
            ->json();

        return $messages['data'] ?? null;
    }

    public function sendRequest(string $url, array $payload): ?array
    {
        $response = Http::withToken(config('services.openai.secret'))
            ->post("https://api.openai.com/v1{$url}", $payload)
            ->json();

        return $response;
    }

    public function requestOpenAI(array $params): ?array
    {
        $type = $params['type'] ?? 'completion';
        unset($params['type']);

        $url = match ($type) {
            'completion' => '/chat/completions',
            'embedding' => '/embeddings',
            'moderation' => '/moderations',
            'assistant' => '/chat/completions', // Fallback (legacy)
            default => '/chat/completions',
        };

        return $this->sendRequest($url, $params);
    }
}
