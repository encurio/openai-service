<?php

declare(strict_types=1);

namespace Encurio\OpenAIService\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Service for interacting with OpenAI APIs (Chat Completions & Assistants).
 *
 * Provides simple, array-based parameter handling for backward compatibility
 * and support for both legacy assistant threads and new tools API.
 */
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
        $this->timeout           = 60;

        $this->apiKeyCompletions = config('services.openai.api_key_completions', '');
        $this->apiKeyAssistants  = config('services.openai.api_key_assistants', '');
    }

    /**
     * Send a chat completion request.
     *
     * @param  array<string,mixed> $opts {
     *     @type array<int,array{role:string,content:string}> $messages
     *     @type string|null   $model       Model name
     *     @type float         $temperature Sampling temperature
     *     @type int           $max_tokens  Max tokens
     *     @type float         $top_p       Top-p
     *     @type string|null   $api_key     Override API key
     *     @type int           $retries     Number of retries
     * }
     * @return array|null
     * @throws Exception
     */
    public function completion(array $opts): ?array
    {
        $defaults = [
            'model'       => 'gpt-4o-mini',
            'temperature' => 0.7,
            'max_tokens'  => 1000,
            'top_p'       => 1.0,
            'api_key'     => null,
            'retries'     => 3,
        ];

        $config = array_merge($defaults, $opts);

        if (empty($config['messages']) || !is_array($config['messages'])) {
            throw new Exception('Missing or invalid "messages" parameter.');
        }

        return $this->requestOpenAI([
            'type'        => 'completion',
            'messages'    => $config['messages'],
            'model'       => $config['model'],
            'temperature' => $config['temperature'],
            'max_tokens'  => $config['max_tokens'],
            'top_p'       => $config['top_p'],
            'api_key'     => $config['api_key'],
            'retries'     => $config['retries'],
        ]);
    }

    /**
     * Send an assistant request, either legacy threads or new tools API.
     *
     * @param  array<string,mixed> $opts {
     *     @type string                             $assistant_id  Assistant identifier
     *     @type array<int,array{role:string,content:string}> $messages      Conversation messages
     *     @type string|null   $model         Model name
     *     @type float         $temperature   Sampling temperature
     *     @type int           $max_tokens    Max tokens
     *     @type float         $top_p         Top-p
     *     @type array<string,mixed>   $tools         Tool definitions
     *     @type array<string,callable> $tool_handlers Tool handlers
     *     @type string|null   $api_key       Override API key
     *     @type int           $retries       Number of retries
     * }
     * @return array|null
     * @throws Exception
     */
    public function assistant(array $opts): ?array
    {
        $defaults = [
            'model'         => 'gpt-4o',
            'temperature'   => 0.7,
            'max_tokens'    => 1000,
            'top_p'         => 1.0,
            'tools'         => [],
            'tool_handlers' => [],
            'api_key'       => null,
            'retries'       => 3,
        ];

        $config = array_merge($defaults, $opts);

        if (empty($config['assistant_id']) || !is_string($config['assistant_id'])) {
            throw new Exception('Missing or invalid "assistant_id" parameter.');
        }
        if (empty($config['messages']) || !is_array($config['messages'])) {
            throw new Exception('Missing or invalid "messages" parameter.');
        }

        // New-style tools API
        if (!empty($config['tools'])) {
            return $this->runAssistantWithTools(
                assistantId:  $config['assistant_id'],
                messages:     $config['messages'],
                tools:        $config['tools'],
                toolHandlers: $config['tool_handlers'],
                model:        $config['model'],
            );
        }

        // Legacy threads API
        return $this->requestOpenAI([
            'type'         => 'assistant',
            'assistant_id' => $config['assistant_id'],
            'messages'     => $config['messages'],
            'model'        => $config['model'],
            'temperature'  => $config['temperature'],
            'max_tokens'   => $config['max_tokens'],
            'top_p'        => $config['top_p'],
            'api_key'      => $config['api_key'],
            'retries'      => $config['retries'],
        ]);
    }

    /**
     * Core request handler.
     *
     * @param  array<string,mixed> $params Array with keys: type, messages, etc.
     * @return array|null
     * @throws Exception
     */
    private function requestOpenAI(array $params): ?array
    {
        $type = $params['type'] ?? 'completion';
        unset($params['type']);

        $apiKey = $params['api_key'] ?? (
        $type === 'assistant'
            ? $this->apiKeyAssistants
            : $this->apiKeyCompletions
        );
        if (empty($apiKey)) {
            throw new Exception('Missing OpenAI API key.');
        }

        $retries  = isset($params['retries']) && is_int($params['retries'])
            ? $params['retries']
            : 3;
        unset($params['api_key'], $params['retries']);

        $endpoint = match ($type) {
            'completion' => '/chat/completions',
            'embedding'  => '/embeddings',
            'moderation' => '/moderations',
            'assistant'  => '/chat/completions',
            default      => '/chat/completions',
        };

        return $this->sendRequest($apiKey, $endpoint, $params, $retries);
    }

    /**
     * Execute tools-based assistant run.
     *
     * @param string $assistantId  Assistant identifier
     * @param array  $messages     Conversation messages
     * @param array  $tools        Tool definitions
     * @param array  $toolHandlers Tool handlers for function calls
     * @param string $model        Model name
     * @return array|null
     */
    public function runAssistantWithTools(
        string $assistantId,
        array  $messages,
        array  $tools,
        array  $toolHandlers,
        string $model
    ): ?array {
        $threadId = $this->createThread();
        if (!$threadId) {
            return null;
        }

        $this->appendMessageToThread($threadId, $messages);
        $runId = $this->startRun($threadId, $assistantId, $model, $tools);
        if (!$runId) {
            return null;
        }

        $this->processToolCalls($threadId, $runId, $toolHandlers);
        return $this->getThreadMessages($threadId);
    }

    /**
     * Create a new assistant thread.
     *
     * @return string|null Thread identifier or null on failure
     */
    private function createThread(): ?string
    {
        $response = Http::withToken($this->apiKeyAssistants)
            ->post('https://api.openai.com/v1/threads', [])
            ->json();

        return $response['id'] ?? null;
    }

    /**
     * Append messages to an existing thread.
     *
     * @param string $threadId Thread identifier
     * @param array  $messages Messages to append
     */
    private function appendMessageToThread(string $threadId, array $messages): void
    {
        Http::withToken($this->apiKeyAssistants)
            ->post("https://api.openai.com/v1/threads/{$threadId}/messages", [
                'role'    => 'user',
                'content' => implode("\n", array_column($messages, 'content')),
            ]);
    }

    /**
     * Start a new run within a thread, optionally invoking tools.
     *
     * @param string $threadId    Thread identifier
     * @param string $assistantId Assistant identifier
     * @param string $model       Model name
     * @param array  $tools       Tool definitions
     * @return string|null Run identifier or null on failure
     */
    private function startRun(string $threadId, string $assistantId, string $model, array $tools): ?string
    {
        $response = Http::withToken($this->apiKeyAssistants)
            ->post("https://api.openai.com/v1/threads/{$threadId}/runs", [
                'assistant_id'    => $assistantId,
                'model'           => $model,
                'tools'           => $tools,
                'tool_choice'     => 'auto',
                'response_format' => 'json',
            ])
            ->json();

        return $response['id'] ?? null;
    }

    /**
     * Poll and process required tool calls for a running assistant thread.
     *
     * @param string $threadId    Thread identifier
     * @param string $runId       Run identifier
     * @param array  $toolHandlers Callable handlers keyed by tool name
     */
    private function processToolCalls(string $threadId, string $runId, array $toolHandlers): void
    {
        do {
            sleep(1);
            $status = Http::withToken($this->apiKeyAssistants)
                ->get("https://api.openai.com/v1/threads/{$threadId}/runs/{$runId}")
                ->json();

            if (
                $status['status'] === 'requires_action' &&
                isset($status['required_action']['submit_tool_outputs'])
            ) {
                $outputs = [];
                foreach ($status['required_action']['submit_tool_outputs']['tool_calls'] as $toolCall) {
                    $name = $toolCall['function']['name'];
                    $args = json_decode($toolCall['function']['arguments'], true);
                    if (isset($toolHandlers[$name]) && is_callable($toolHandlers[$name])) {
                        $outputs[] = [
                            'tool_call_id' => $toolCall['id'],
                            'output'       => call_user_func($toolHandlers[$name], $args),
                        ];
                    }
                }
                Http::withToken($this->apiKeyAssistants)
                    ->post("https://api.openai.com/v1/threads/{$threadId}/runs/{$runId}/submit_tool_outputs", [
                        'tool_outputs' => $outputs,
                    ]);
            }
        } while (in_array($status['status'], ['queued', 'in_progress', 'requires_action'], true));
    }

    /**
     * Retrieve all messages from a completed assistant thread.
     *
     * @param string $threadId Thread identifier
     * @return array|null List of messages or null on failure
     */
    private function getThreadMessages(string $threadId): ?array
    {
        $messages = Http::withToken($this->apiKeyAssistants)
            ->get("https://api.openai.com/v1/threads/{$threadId}/messages")
            ->json();

        return $messages['data'] ?? null;
    }

    /**
     * Low-level HTTP request sender.
     *
     * @param string $apiKey  Bearer token
     * @param string $url     Full URL or endpoint path
     * @param array  $payload JSON payload
     * @param int    $retries Retry attempts
     * @return array|null
     */
    private function sendRequest(string $apiKey, string $url, array $payload, int $retries): ?array
    {
        for ($i = 0; $i < $retries; $i++) {
            try {
                $response = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Authorization' => "Bearer {$apiKey}",
                        'Content-Type'  => 'application/json',
                    ])
                    ->post($url, $payload);

                if ($response->successful()) {
                    return $response->json();
                }

                Log::error('OpenAI API Error', ['response' => $response->body()]);
            } catch (Exception $e) {
                Log::error('OpenAI API Timeout/Error', ['message' => $e->getMessage()]);
                sleep(1);
            }
        }

        return null;
    }
}
