<?php
declare(strict_types=1);

namespace Encurio\OpenAIService\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Service for interacting with OpenAI APIs (Chat Completions & Assistants).
 *
 * Reads configuration from config/openai.php:
 *   - keys.completions
 *   - keys.assistants
 *   - retries
 *   - timeout
 *   - endpoints.completions
 *   - endpoints.assistants
 */
class OpenAIService
{
    private string $keyCompletions;
    private string $keyAssistants;
    private int    $retries;
    private int    $timeout;
    private string $baseUrlCompletions;
    private string $baseUrlAssistants;

    /**
     * Load API keys, retry count, timeout and endpoints from config/openai.php
     */
    public function __construct()
    {
        // API keys
        $this->keyCompletions     = config('openai.keys.completions', '');
        $this->keyAssistants      = config('openai.keys.assistants', '');
        // Retry and timeout settings
        $this->retries            = config('openai.retries', 3);
        $this->timeout            = config('openai.timeout', 60);
        // Endpoint URLs (can be overridden in config)
        $this->baseUrlCompletions = config(
            'openai.endpoints.completions',
            'https://api.openai.com/v1/chat/completions'
        );
        $this->baseUrlAssistants  = config(
            'openai.endpoints.assistants',
            'https://api.openai.com/v1/assistants'
        );
    }

    /**
     * Chat Completion wrapper.
     *
     * @param  array<string,mixed> $opts {
     *     @type array<int,array{role:string,content:string}> $messages    Conversation messages
     *     @type string|null    $model       Model name (default gpt-4o-mini)
     *     @type float          $temperature Sampling temperature
     *     @type int            $max_tokens  Maximum tokens
     *     @type float          $top_p       Nucleus sampling parameter
     *     @type string|null    $api_key     Override API key
     *     @type int|null       $retries     Override retry count
     * }
     * @return array|null
     * @throws Exception on missing parameters or API key
     */
    public function completion(array $opts): ?array
    {
        $defaults = [
            'model'       => config('openai.defaults.model', 'gpt-4o-mini'),
            'temperature' => config('openai.defaults.temperature', 0.7),
            'max_tokens'  => config('openai.defaults.max_tokens', 1000),
            'top_p'       => config('openai.defaults.top_p', 1.0),
            'api_key'     => $this->keyCompletions,
            'retries'     => $this->retries,
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
     * Assistant wrapper (legacy threads or new Tools API).
     *
     * @param  array<string,mixed> $opts {
     *     @type string                           $assistant_id  ID of the assistant
     *     @type array<int,array{role:string,content:string}> $messages      Conversation messages
     *     @type string|null    $model         Model name (default gpt-4o)
     *     @type float          $temperature   Sampling temperature
     *     @type int            $max_tokens    Maximum tokens
     *     @type float          $top_p         Nucleus sampling parameter
     *     @type array<string,mixed>   $tools          Tool definitions for new API
     *     @type array<string,callable> $tool_handlers  Callbacks for tools
     *     @type string|null    $api_key       Override API key
     *     @type int|null       $retries       Override retry count
     * }
     * @return array|null
     * @throws Exception on missing parameters or API key
     */
    public function assistant(array $opts): ?array
    {
        $defaults = [
            'model'         => config('openai.defaults.assistant_model', 'gpt-4o'),
            'temperature'   => config('openai.defaults.temperature', 0.7),
            'max_tokens'    => config('openai.defaults.max_tokens', 1000),
            'top_p'         => config('openai.defaults.top_p', 1.0),
            'tools'         => [],
            'tool_handlers' => [],
            'api_key'       => $this->keyAssistants,
            'retries'       => $this->retries,
        ];
        $config = array_merge($defaults, $opts);

        if (empty($config['assistant_id']) || !is_string($config['assistant_id'])) {
            throw new Exception('Missing or invalid "assistant_id" parameter.');
        }
        if (empty($config['messages']) || !is_array($config['messages'])) {
            throw new Exception('Missing or invalid "messages" parameter.');
        }

        // New Tools API flow
        if (!empty($config['tools'])) {
            return $this->runAssistantWithTools(
                assistantId:  $config['assistant_id'],
                messages:     $config['messages'],
                tools:        $config['tools'],
                toolHandlers: $config['tool_handlers'],
                model:        $config['model']
            );
        }

        // Legacy Threads API flow
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
     * Core request handler; publicly accessible.
     *
     * @param  array<string,mixed> $params Array with keys: type, messages, etc.
     * @return array|null
     * @throws Exception on missing API key
     */
    public function requestOpenAI(array $params): ?array
    {
        $type   = $params['type'] ?? 'completion';
        $apiKey = $params['api_key'] ?? (
        $type === 'assistant'
            ? $this->keyAssistants
            : $this->keyCompletions
        );
        if (empty($apiKey)) {
            throw new Exception('Missing OpenAI API key.');
        }

        $retries = is_int($params['retries'] ?? null)
            ? $params['retries']
            : $this->retries;
        unset($params['type'], $params['api_key'], $params['retries']);

        if ($type === 'assistant') {
            // Build the threads endpoint
            $assistantId = $params['assistant_id'] ?? '';
            unset($params['assistant_id']);
            $url = "{$this->baseUrlAssistants}/{$assistantId}/threads";
        } else {
            // Use chat completions endpoint
            $url = $this->baseUrlCompletions;
        }

        return $this->sendRequest($apiKey, $url, $params, $retries);
    }

    /**
     * Execute a tools-based assistant run (new API).
     *
     * @param string $assistantId  Assistant ID
     * @param array  $messages     Messages to send
     * @param array  $tools        Tool definitions
     * @param array  $toolHandlers Tool callbacks
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
     * @return string|null Thread ID or null on failure
     */
    private function createThread(): ?string
    {
        $response = Http::withToken($this->keyAssistants)
            ->post("{$this->baseUrlAssistants}/threads", [])
            ->json();

        return $response['id'] ?? null;
    }

    /**
     * Append messages to an existing thread.
     *
     * @param string $threadId Thread ID
     * @param array  $messages Messages to append
     */
    private function appendMessageToThread(string $threadId, array $messages): void
    {
        Http::withToken($this->keyAssistants)
            ->post("{$this->baseUrlAssistants}/threads/{$threadId}/messages", [
                'role'    => 'user',
                'content' => implode("\n", array_column($messages, 'content')),
            ]);
    }

    /**
     * Start a new run within a thread, optionally invoking tools.
     *
     * @param string $threadId    Thread ID
     * @param string $assistantId Assistant ID
     * @param string $model       Model name
     * @param array  $tools       Tool definitions
     * @return string|null Run ID or null on failure
     */
    private function startRun(string $threadId, string $assistantId, string $model, array $tools): ?string
    {
        $response = Http::withToken($this->keyAssistants)
            ->post("{$this->baseUrlAssistants}/threads/{$threadId}/runs", [
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
     * @param string $threadId    Thread ID
     * @param string $runId       Run ID
     * @param array  $toolHandlers Callable handlers keyed by tool name
     */
    private function processToolCalls(string $threadId, string $runId, array $toolHandlers): void
    {
        do {
            sleep(2); // Prevent tight-loop polling
            $status = Http::withToken($this->keyAssistants)
                ->get("{$this->baseUrlAssistants}/threads/{$threadId}/runs/{$runId}")
                ->json();

            if (
                $status['status'] === 'requires_action' &&
                isset($status['required_action']['submit_tool_outputs'])
            ) {
                $outputs = [];
                foreach ($status['required_action']['submit_tool_outputs']['tool_calls'] as $call) {
                    $name = $call['function']['name'];
                    $args = json_decode($call['function']['arguments'], true);
                    if (isset($toolHandlers[$name]) && is_callable($toolHandlers[$name])) {
                        $outputs[] = [
                            'tool_call_id' => $call['id'],
                            'output'       => call_user_func($toolHandlers[$name], $args),
                        ];
                    }
                }
                Http::withToken($this->keyAssistants)
                    ->post("{$this->baseUrlAssistants}/threads/{$threadId}/runs/{$runId}/submit_tool_outputs", [
                        'tool_outputs' => $outputs,
                    ]);
            }
        } while (in_array($status['status'], ['queued', 'in_progress', 'requires_action'], true));
    }

    /**
     * Retrieve all messages from a completed assistant thread.
     *
     * @param string $threadId Thread ID
     * @return array|null List of messages or null on failure
     */
    private function getThreadMessages(string $threadId): ?array
    {
        $messages = Http::withToken($this->keyAssistants)
            ->get("{$this->baseUrlAssistants}/threads/{$threadId}/messages")
            ->json();

        return $messages['data'] ?? null;
    }

    /**
     * Low-level HTTP request sender with retry and timeout.
     *
     * @param string $apiKey  Bearer token
     * @param string $url     Full endpoint URL
     * @param array  $payload JSON payload
     * @param int    $retries Number of retry attempts
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

                Log::error('OpenAI API Error', ['status' => $response->status(), 'body' => $response->body()]);
            } catch (Exception $e) {
                Log::warning('OpenAI API Exception', ['message' => $e->getMessage()]);
                sleep(1);
            }
        }

        return null;
    }
}
