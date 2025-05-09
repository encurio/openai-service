<?php
declare(strict_types=1);

namespace Encurio\OpenAIService\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Service for interacting with OpenAI’s APIs: Completions, Embeddings,
 * Moderations, Images, and Assistants (Threads + optional Tools).
 *
 * Configuration is pulled from config/openai.php:
 *   - keys.completions
 *   - keys.assistants
 *   - retries
 *   - timeout
 *   - endpoints.completions
 *   - endpoints.threads
 *   - endpoints.embeddings
 *   - endpoints.moderations
 *   - endpoints.images
 *   - defaults.model
 *   - defaults.assistant_model
 *   - defaults.temperature
 *   - defaults.max_tokens
 *   - defaults.top_p
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
     * Constructor.
     *
     * Loads API keys, retry count, timeout and all endpoint URLs
     * from config/openai.php, falling back to the official defaults.
     *
     * @throws Exception if any required config is missing
     */
    public function __construct()
    {
        // Load API keys
        $this->keyCompletions = config(
            'openai.keys.completions',
            ''
        );
        $this->keyAssistants  = config(
            'openai.keys.assistants',
            ''
        );

        if (empty($this->keyCompletions) || empty($this->keyAssistants)) {
            throw new Exception(
                'OpenAI API keys for completions or assistants are not set in config/openai.php'
            );
        }

        // Global retry and timeout settings
        $this->retries = config('openai.retries', 3);
        $this->timeout = config('openai.timeout', 60);

        // Load each endpoint URL from config, with fallback
        $this->baseUrlCompletions = config(
            'openai.endpoints.completions',
            'https://api.openai.com/v1/chat/completions'
        );
        $this->baseUrlThreads = config(
            'openai.endpoints.threads',
            'https://api.openai.com/v1/threads'
        );
        $this->baseUrlEmbeddings = config(
            'openai.endpoints.embeddings',
            'https://api.openai.com/v1/embeddings'
        );
        $this->baseUrlModerations = config(
            'openai.endpoints.moderations',
            'https://api.openai.com/v1/moderations'
        );
        $this->baseUrlImages = config(
            'openai.endpoints.images',
            'https://api.openai.com/v1/images/generations'
        );
    }

    /**
     * Send a chat completion request.
     *
     * @param  array<string,mixed> $opts {
     *     @type array<int,array{role:string,content:string}> $messages     List of chat messages.
     *     @type string|null    $model        Model to use (default from config).
     *     @type float          $temperature  Sampling temperature.
     *     @type int            $max_tokens   Maximum number of tokens.
     *     @type float          $top_p        Nucleus sampling parameter.
     *     @type string|null    $api_key      Override API key.
     *     @type int|null       $retries      Override retry count.
     * }
     * @return array|null Parsed JSON response, or null on failure.
     * @throws Exception on missing parameters or API key.
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
            throw new Exception(
                'Missing or invalid "messages" parameter for completion().'
            );
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
     * Send an assistant request via the Threads API (supports tools).
     *
     * @param  array<string,mixed> $opts {
     *     @type string                           $assistant_id   ID of the assistant.
     *     @type array<int,array{role:string,content:string}> $messages       Chat messages.
     *     @type string|null    $model          Model to use (default from config).
     *     @type array<string,mixed>   $tools           Definitions of tools.
     *     @type array<string,callable> $tool_handlers   Callbacks for tool calls.
     * }
     * @return array|null Final list of messages from the thread, or null.
     * @throws Exception on missing parameters or API key.
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
            throw new Exception(
                'Missing or invalid "assistant_id" parameter for assistant().'
            );
        }
        if (empty($config['messages']) || !is_array($config['messages'])) {
            throw new Exception(
                'Missing or invalid "messages" parameter for assistant().'
            );
        }

        // Always use the full Threads flow, even if tools is empty
        return $this->runAssistantWithTools(
            assistantId:  $config['assistant_id'],
            messages:     $config['messages'],
            tools:        $config['tools'],
            toolHandlers: $config['tool_handlers'],
            model:        $config['model']
        );
    }

    /**
     * Core handler for all non-assistant request types:
     * completion, embedding, moderation, images.
     *
     * @param  array<string,mixed> $params Must include:
     *   - type      => request type string
     *   - api_key   => (optional override API key)
     *   - retries   => (optional override retry count)
     *   - plus payload keys per type:
     *     - messages for completion
     *     - input for embedding/moderation
     *     - prompt/etc for images
     * @return array|null Parsed JSON response, or null on failures.
     * @throws Exception on missing API key or unknown type.
     */
    public function requestOpenAI(array $params): ?array
    {
        $type   = $params['type'] ?? 'completion';
        $apiKey = $params['api_key']
            ?? ($type === 'assistant'
                ? $this->keyAssistants
                : $this->keyCompletions
            );

        if (empty($apiKey)) {
            throw new Exception(
                "Missing OpenAI API key for request type \"{$type}\"."
            );
        }

        // Determine retry count override
        $retries = is_int($params['retries'] ?? null)
            ? $params['retries']
            : $this->retries;

        // Remove internal-only params
        unset($params['type'], $params['api_key'], $params['retries']);

        // Select endpoint based on type
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
                throw new Exception(
                    "Unknown OpenAI request type \"{$type}\" in requestOpenAI()."
                );
        }

        return $this->sendRequest($apiKey, $url, $params, $retries);
    }

    /**
     * Implements the full Threads-based assistant flow:
     * 1) create thread, 2) append messages, 3) start run,
     * 4) poll & process tool calls, 5) fetch all messages.
     *
     * @param string   $assistantId  ID of the assistant.
     * @param array    $messages     Chat messages to initiate the thread.
     * @param array    $tools        Tool definitions for the run.
     * @param array    $toolHandlers Callbacks for tool calls.
     * @param string   $model        Model to use.
     * @return array|null List of all messages in thread, or null.
     * @throws Exception on thread/run creation failure.
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
        $threadId = $thread['id']
            ?? throw new Exception('Failed to create assistant thread.');

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
        $runId = $run['id']
            ?? throw new Exception('Failed to start assistant run.');

        // 4) POLL & PROCESS TOOL CALLS
        $this->processToolCalls($threadId, $runId, $toolHandlers);

        // 5) RETRIEVE FINAL MESSAGES
        return $this->getThreadMessages($threadId);
    }

    /**
     * Polls a running assistant thread for 'requires_action' events,
     * executes the corresponding tool handlers, and submits outputs.
     *
     * @param string $threadId      Thread ID.
     * @param string $runId         Run ID.
     * @param array  $toolHandlers  Callbacks keyed by tool name.
     */
    private function processToolCalls(
        string $threadId,
        string $runId,
        array  $toolHandlers
    ): void {
        do {
            // Prevent tight-looping and respect rate limits
            sleep(2);

            $status = Http::withToken($this->keyAssistants)
                ->get("{$this->baseUrlThreads}/{$threadId}/runs/{$runId}")
                ->json();

            if (
                ($status['status'] ?? '') === 'requires_action'
                && isset($status['required_action']['submit_tool_outputs'])
            ) {
                $outputs = [];
                foreach (
                    $status['required_action']['submit_tool_outputs']['tool_calls']
                    as $call
                ) {
                    $name = $call['function']['name'];
                    $args = json_decode($call['function']['arguments'], true);
                    if (
                        isset($toolHandlers[$name])
                        && is_callable($toolHandlers[$name])
                    ) {
                        $outputs[] = [
                            'tool_call_id' => $call['id'],
                            'output'       => call_user_func(
                                $toolHandlers[$name],
                                $args
                            ),
                        ];
                    }
                }

                Http::withToken($this->keyAssistants)
                    ->post("{$this->baseUrlThreads}/{$threadId}/runs/{$runId}/submit_tool_outputs", [
                        'tool_outputs' => $outputs,
                    ]);
            }
        } while (
            in_array(
                $status['status'] ?? '',
                ['queued', 'in_progress', 'requires_action'],
                true
            )
        );
    }

    /**
     * Retrieves all messages from a completed assistant thread.
     *
     * @param string $threadId Thread ID.
     * @return array|null List of messages, or null on failure.
     */
    private function getThreadMessages(string $threadId): ?array
    {
        $response = Http::withToken($this->keyAssistants)
            ->get("{$this->baseUrlThreads}/{$threadId}/messages")
            ->json();

        return $response['data'] ?? null;
    }

    /**
     * Low-level HTTP request sender with retry and timeout logic.
     *
     * @param string $apiKey  Bearer token.
     * @param string $url     Full URL to POST.
     * @param array  $payload JSON payload.
     * @param int    $retries Number of retry attempts.
     * @return array|null Parsed JSON response or null after retries.
     */
    private function sendRequest(
        string $apiKey,
        string $url,
        array  $payload,
        int    $retries
    ): ?array {
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
                Log::warning('OpenAI HTTP Exception', [
                    'message' => $e->getMessage(),
                ]);
                sleep(1);
            }
        }

        return null;
    }
}
