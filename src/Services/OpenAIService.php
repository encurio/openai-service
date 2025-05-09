<?php
declare(strict_types=1);

namespace Encurio\OpenAIService\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;
use Illuminate\Support\Str;

/**
 * Service for interacting with OpenAI APIs:
 *  - Chat Completions
 *  - Embeddings
 *  - Moderations
 *  - Images
 *  - Assistants via Threads (with optional Tools)
 *
 * Configuration is pulled from config/openai.php:
 *  - keys.completions
 *  - keys.assistants
 *  - retries
 *  - timeout
 *  - endpoints.completions
 *  - endpoints.threads
 *  - endpoints.embeddings
 *  - endpoints.moderations
 *  - endpoints.images
 *  - defaults.model
 *  - defaults.assistant_model
 *  - defaults.temperature
 *  - defaults.max_tokens
 *  - defaults.top_p
 */
class OpenAIService
{
    private string $keyCompletions;
    private string $keyAssistants;
    private int    $retries;
    private int    $timeout;

    private string $baseUrlCompletions;
    private string $baseUrlThreads;
    private string $baseUrlEmbeddings;
    private string $baseUrlModerations;
    private string $baseUrlImages;

    /**
     * Load API keys, retry count, timeout and all endpoints from config/openai.php.
     */
    public function __construct()
    {
        // API keys
        $this->keyCompletions = config('openai.keys.completions', '');
        $this->keyAssistants  = config('openai.keys.assistants', '');
        // Global retry and timeout settings
        $this->retries        = config('openai.retries', 3);
        $this->timeout        = config('openai.timeout', 60);
        // Endpoints for each API
        $this->baseUrlCompletions = config('openai.endpoints.completions');
        $this->baseUrlThreads     = config('openai.endpoints.threads');
        $this->baseUrlEmbeddings  = config('openai.endpoints.embeddings');
        $this->baseUrlModerations = config('openai.endpoints.moderations');
        $this->baseUrlImages      = config('openai.endpoints.images');
    }

    /**
     * Chat Completion wrapper (uses chat/completions endpoint).
     *
     * @param  array<string,mixed> $opts {
     *     @type array<int,array{role:string,content:string}> $messages    Chat messages
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
            throw new Exception('Missing or invalid "messages" parameter for completion().');
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
     * Assistant wrapper: always uses the Threads API flow (with optional tools).
     *
     * @param  array<string,mixed> $opts {
     *     @type string                           $assistant_id   Assistant ID
     *     @type array<int,array{role:string,content:string}> $messages       Chat messages
     *     @type string|null    $model          Model name (default gpt-4o)
     *     @type float          $temperature    Sampling temperature
     *     @type int            $max_tokens     Maximum tokens
     *     @type float          $top_p          Nucleus sampling parameter
     *     @type array<string,mixed>   $tools           Tool definitions
     *     @type array<string,callable> $tool_handlers   Callbacks for tools
     * }
     * @return array|null
     * @throws Exception on missing parameters or API key
     */
    public function assistant(array $opts): ?array
    {
        $defaults = [
            'model'         => config('openai.defaults.assistant_model', 'gpt-4o'),
            'tools'         => [],
            'tool_handlers' => [],
        ];
        $config = array_merge($defaults, $opts);

        if (empty($config['assistant_id']) || !is_string($config['assistant_id'])) {
            throw new Exception('Missing or invalid "assistant_id" parameter for assistant().');
        }
        if (empty($config['messages']) || !is_array($config['messages'])) {
            throw new Exception('Missing or invalid "messages" parameter for assistant().');
        }

        // Always drive the full Threads flow, even if no tools specified
        return $this->runAssistantWithTools(
            assistantId:  $config['assistant_id'],
            messages:     $config['messages'],
            tools:        $config['tools'],
            toolHandlers: $config['tool_handlers'],
            model:        $config['model']
        );
    }

    /**
     * Core request handler for non-assistant types.
     *
     * @param  array<string,mixed> $params Must include:
     *   - type: 'completion'|'embedding'|'moderation'|'images'
     *   - messages|input|... as required per type
     *   - api_key   (optional override)
     *   - retries   (optional override)
     * @return array|null
     * @throws Exception on missing API key or unknown type
     */
    public function requestOpenAI(array $params): ?array
    {
        $type   = $params['type'] ?? 'completion';
        $apiKey = $params['api_key']
            ?? ($type === 'assistant' ? $this->keyAssistants : $this->keyCompletions);

        if (empty($apiKey)) {
            throw new Exception("Missing OpenAI API key for request type \"{$type}\".");
        }

        // Determine retry count
        $retries = is_int($params['retries'] ?? null)
            ? $params['retries']
            : $this->retries;

        // Clean up internal-only params
        unset($params['type'], $params['api_key'], $params['retries']);

        // Map request types to their configured endpoints
        switch ($type) {
            case 'completion':
                $url = $this->baseUrlCompletions;
                break;

            case 'embedding':
                $url = $this->baseUrlEmbeddings;
                break;

            case 'moderation':
                $url = $this->baseUrlModerations;
                break;

            case 'images':
                $url = $this->baseUrlImages;
                break;

            default:
                throw new Exception("Unknown OpenAI request type \"{$type}\" in requestOpenAI().");
        }

        return $this->sendRequest($apiKey, $url, $params, $retries);
    }

    /**
     * Full Threads-based assistant run (supports tools).
     *
     * @param string   $assistantId  Assistant ID
     * @param array    $messages     Messages to start the conversation
     * @param array    $tools        Tool definitions
     * @param array    $toolHandlers Tool callbacks
     * @param string   $model        Model name
     * @return array|null Final list of thread messages, or null on failure
     * @throws Exception on thread or run creation failure
     */
    public function runAssistantWithTools(
        string $assistantId,
        array  $messages,
        array  $tools,
        array  $toolHandlers,
        string $model
    ): ?array {
        // 1) CREATE THREAD
        $thread = Http::withToken($this->keyAssistants)
            ->post($this->baseUrlThreads, [])
            ->json();
        $threadId = $thread['id'] ?? throw new Exception('Failed to create assistant thread.');

        // 2) APPEND MESSAGES
        Http::withToken($this->keyAssistants)
            ->post("{$this->baseUrlThreads}/{$threadId}/messages", [
                'role'    => 'user',
                'content' => implode("\n", array_column($messages, 'content')),
            ]);

        // 3) START RUN (tools or not)
        $run = Http::withToken($this->keyAssistants)
            ->post("{$this->baseUrlThreads}/{$threadId}/runs", [
                'assistant_id'    => $assistantId,
                'model'           => $model,
                'tools'           => $tools,
                'tool_choice'     => 'auto',
                'response_format' => 'json',
            ])
            ->json();
        $runId = $run['id'] ?? throw new Exception('Failed to start assistant run.');

        // 4) POLL & PROCESS REQUIRED TOOL CALLS
        $this->processToolCalls($threadId, $runId, $toolHandlers);

        // 5) RETRIEVE FINAL MESSAGES
        return $this->getThreadMessages($threadId);
    }

    /**
     * Poll for 'requires_action' status and submit tool outputs.
     *
     * @param string   $threadId    Thread ID
     * @param string   $runId       Run ID
     * @param array    $toolHandlers Callbacks keyed by tool name
     */
    private function processToolCalls(string $threadId, string $runId, array $toolHandlers): void
    {
        do {
            sleep(2); // prevent tight-loop polling & respect rate limits

            $status = Http::withToken($this->keyAssistants)
                ->get("{$this->baseUrlThreads}/{$threadId}/runs/{$runId}")
                ->json();

            if (
                ($status['status'] ?? '') === 'requires_action' &&
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
                    ->post("{$this->baseUrlThreads}/{$threadId}/runs/{$runId}/submit_tool_outputs", [
                        'tool_outputs' => $outputs,
                    ]);
            }
        } while (in_array($status['status'] ?? '', ['queued', 'in_progress', 'requires_action'], true));
    }

    /**
     * Fetch all messages from a completed thread.
     *
     * @param string $threadId Thread ID
     * @return array|null Array of messages or null on failure
     */
    private function getThreadMessages(string $threadId): ?array
    {
        $response = Http::withToken($this->keyAssistants)
            ->get("{$this->baseUrlThreads}/{$threadId}/messages")
            ->json();

        return $response['data'] ?? null;
    }

    /**
     * Low-level HTTP request with retry & timeout handling.
     *
     * @param string $apiKey  Bearer token
     * @param string $url     Full endpoint URL
     * @param array  $payload JSON payload
     * @param int    $retries Number of retry attempts
     * @return array|null Parsed JSON response or null on repeated failures
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

                Log::error('OpenAI API Error', [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
            } catch (Exception $e) {
                Log::warning('OpenAI HTTP Exception', ['message' => $e->getMessage()]);
                sleep(1);
            }
        }

        return null;
    }
}
