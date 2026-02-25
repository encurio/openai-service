<?php
declare(strict_types=1);

namespace Encurio\OpenAIService\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Encurio\OpenAIService\Exceptions\OpenAIRunFailedException;
use Exception;

/**
 * Universal OpenAI Service:
 *
 *  - Chat Completions (stateless)
 *  - Embeddings
 *  - Moderations
 *  - Images
 *  - Threads API (stateful Assistants + Tools)
 *
 * Config (config/openai.php) must define:
 *   keys.completions, keys.assistants,
 *   retries, timeout,
 *   endpoints.completions, .embeddings, .moderations, .images, .threads,
 *   defaults.model, .assistant_model, .temperature, .max_tokens, .top_p
 */
class OpenAIService
{
    /**
     * Header for all Threads calls (Assistants API v2).
     */
    private const ASSISTANTS_V2_HEADER = [
        'OpenAI-Beta' => 'assistants=v2',
    ];

    //––– Configuration values –––
    private string $keyCompletions;
    private string $keyAssistants;
    private int    $retries;
    private int    $timeout;

    private string $baseUrlCompletions;
    private string $baseUrlEmbeddings;
    private string $baseUrlModerations;
    private string $baseUrlImages;
    private string $baseUrlThreads;
    private string $baseUrlFiles;

    private ?string $projectApiKey = null;

    private $pollResponse = '';

    /**
     * Constructor: load keys, retries, timeout and all endpoints.
     *
     * @throws Exception if required API keys are missing
     */
    public function __construct()
    {
        // Load API keys
        $this->keyCompletions = config('openai.keys.completions', '');
        $this->keyAssistants  = config('openai.keys.assistants', '');
        if (empty($this->keyCompletions) || empty($this->keyAssistants)) {
            throw new Exception('OpenAI API keys are not set in config/openai.php');
        }

        // Retries & timeout
        $this->retries = config('openai.retries', 3);
        $this->timeout = config('openai.timeout', 60);

        // Endpoints
        $this->baseUrlCompletions = config(
            'openai.endpoints.completions',
            'https://api.openai.com/v1/chat/completions'
        );
        $this->baseUrlEmbeddings  = config(
            'openai.endpoints.embeddings',
            'https://api.openai.com/v1/embeddings'
        );
        $this->baseUrlModerations = config(
            'openai.endpoints.moderations',
            'https://api.openai.com/v1/moderations'
        );
        $this->baseUrlImages      = config(
            'openai.endpoints.images',
            'https://api.openai.com/v1/images/generations'
        );
        $this->baseUrlThreads     = config(
            'openai.endpoints.threads',
            'https://api.openai.com/v1/threads'
        );
        $this->baseUrlFiles       = config(
            'openai.endpoints.files',
            'https://api.openai.com/v1/files'
        );
    }

    /**
     * Set an override API key for the current request cycle.
     *
     * @param string $apiKey
     * @return void
     */
    public function setProjectApiKey(string $apiKey): void
    {
        $this->projectApiKey = $apiKey;
    }

    public function getPollResponse()
    {
        return $this->pollResponse;
    }


    //==============================================================================
    // 1) Stateless Chat Completions + Helpers
    //==============================================================================

    /**
     * Shortcut for a chat completion.
     *
     * @param  array<string,mixed> $opts {
     *     @type array<int,array{role:string,content:string|array}> $messages    messages list
     *     @type string|null    $model       model name
     *     @type float          $temperature temperature
     *     @type int            $max_tokens  max tokens
     *     @type float          $top_p       nucleus sampling
     *     @type array|null     $response_format response format
     *     @type string|null    $api_key     override key
     *     @type int|null       $retries     override retries
     * }
     * @return array|null         OpenAI JSON response or null
     * @throws Exception on missing params/key
     */
    public function completion(array $opts): ?array
    {
        // fill defaults from config/openai.php
        $defaults = [
            'model'           => config('openai.defaults.model', 'gpt-4o-mini'),
            'temperature'     => config('openai.defaults.temperature', 0.7),
            'max_tokens'      => config('openai.defaults.max_tokens', 1000),
            'top_p'           => config('openai.defaults.top_p', 1.0),
            'response_format' => null,
            'api_key'         => (!empty($this->projectApiKey) ? $this->projectApiKey : $this->keyCompletions),
            'retries'         => $this->retries,
        ];
        $cfg = array_merge($defaults, $opts);

        if (empty($cfg['messages']) || ! is_array($cfg['messages'])) {
            throw new Exception('Missing or invalid "messages" for completion().');
        }

        $params = [
            'type'        => 'completion',
            'messages'    => $cfg['messages'],
            'model'       => $cfg['model'],
            'temperature' => $cfg['temperature'],
            'max_tokens'  => $cfg['max_tokens'],
            'top_p'       => $cfg['top_p'],
            'api_key'     => $cfg['api_key'],
            'retries'     => $cfg['retries'],
        ];

        if (!empty($cfg['response_format'])) {
            $params['response_format'] = $cfg['response_format'];
        }

        return $this->requestOpenAI($params);
    }

        /**
     * Generate images via OpenAI Images API.
     *
     * @param  array<string,mixed> $opts {
     *     @type string         $prompt            Required. Text prompt for the image.
     *     @type string|null     $model            Optional. Defaults to 'gpt-image-1'.
     *     @type int|null        $n                Optional. Defaults to 1.
     *     @type string|null     $size             Optional. Defaults to '1024x1024'.
     *     @type string|null     $response_format  Optional. 'url' or 'b64_json'. Defaults to 'url'.
     *     @type string|null     $api_key          Optional. Override key (uses completions key by default).
     *     @type int|null        $retries          Optional. Override retries.
     * }
     * @return array|null         OpenAI JSON response or null
     * @throws Exception on missing params
     */
    public function image(array $opts): ?array
    {
        $cfg = array_merge([
            'model'            => 'gpt-image-1',   // default model for images
            'prompt'           => '',
            'n'                => 1,
            'size'             => '1024x1024',
            'response_format'  => 'url',
            'api_key'          => null,
            'retries'          => null,
        ], $opts);
    
        if (!is_string($cfg['prompt']) || $cfg['prompt'] === '') {
            throw new Exception('Missing or invalid "prompt" for image().');
        }
    
        return $this->requestOpenAI([
            'type'             => 'images',
            'model'            => $cfg['model'],
            'prompt'           => $cfg['prompt'],
            'n'                => $cfg['n'],
            'size'             => $cfg['size'],
            'response_format'  => $cfg['response_format'],
            'api_key'          => $cfg['api_key'],
            'retries'          => $cfg['retries'],
        ]);
    }

    /**
     * Core generic requester for non-thread types.
     *
     * @param  array<string,mixed> $params {
     *     @type string        $type     one of: completion, embedding, moderation, images
     *     @type string|null   $api_key  override API key
     *     @type int|null      $retries  override retries
     *     // plus payload fields: messages, input, prompt, etc.
     * }
     * @return array|null               JSON response or null
     * @throws Exception on missing key or unknown type
     */
    public function requestOpenAI(array $params): ?array
    {
        $type   = $params['type'] ?? 'completion';
        $apiKey = $params['api_key']
            ?? $this->projectApiKey
            ?? match ($type) {
                'completion', 'images'     => $this->keyCompletions,
                'embedding', 'moderation'  => $this->keyAssistants,
                default                    => throw new Exception("Unknown request type \"$type\"."),
            };

        if (empty($apiKey)) {
            throw new Exception("Missing API key for type \"$type\".");
        }

        $retries = is_int($params['retries'] ?? null)
            ? $params['retries']
            : $this->retries;

        // remove internals
        unset($params['type'], $params['api_key'], $params['retries']);

        // choose endpoint
        switch ($type) {
            case 'completion':
                $url = $this->baseUrlCompletions; break;
            case 'embedding':
                $url = $this->baseUrlEmbeddings; break;
            case 'moderation':
                $url = $this->baseUrlModerations; break;
            case 'images':
                $url = $this->baseUrlImages; break;
            default:
                throw new Exception("Unknown type \"$type\".");
        }

        return $this->sendRequest($apiKey, $url, $params, $retries);
    }

    //==============================================================================
    // 2) Stateful Threads API Helpers (Assistants + Tools)
    //==============================================================================

    /**
     * returns an API key for the OpenAI assistant.
     * @return \Illuminate\Config\Repository|\Illuminate\Foundation\Application|mixed|object|string|null
     */
    private function _getAssistantKey() {
        return (!empty($this->projectApiKey)
            ? $this->projectApiKey
            : $this->keyAssistants);
    }

    /**
     * Create a new assistant Thread.
     *
     * @return string               New thread_id
     * @throws Exception on HTTP error
     */
    public function createThread(): string
    {
        $resp = Http::withToken($this->_getAssistantKey())
            ->withHeaders(self::ASSISTANTS_V2_HEADER)
            ->post($this->baseUrlThreads, (object)[])
            ->throw()
            ->json();

        return $resp['id'] ?? throw new Exception('createThread: no id returned');
    }

    /**
     * Append one or more messages to an existing thread.
     *
     * @param string $threadId
     * @param array<int,array{role:string,content:string|array}> $messages
     */
    public function appendMessageToThread(string $threadId, array $messages): void
    {
        foreach ($messages as $msg) {
            $payload = [
                'role'    => $msg['role'],
                'content' => $this->formatContent($msg['content']),
            ];

            $resp = Http::withToken($this->_getAssistantKey())
                ->withHeaders(self::ASSISTANTS_V2_HEADER)
                ->post("{$this->baseUrlThreads}/{$threadId}/messages", $payload)
                ->throw();
        }
    }

    /**
     * Normalize your content into the shape the API expects.
     *
     * @param  string|array  $content
     * @return string|array
     */
    private function formatContent(string|array $content): string|array
    {
        // if it's already an array of structured parts, just trust it
        if (is_array($content) && isset($content[0]['type'])) {
            return $content;
        }

        // otherwise wrap a simple string into the array-of-text-parts form
        return [
            [
                'type' => 'text',
                'text' => (string) $content,
            ],
        ];
    }

    /**
     * Upload a file to OpenAI.
     *
     * @param  string  $filePath   Absolute path to the local file
     * @param  string  $purpose    e.g. 'vision', 'assistants', 'fine-tune'
     * @return string               The OpenAI file_id
     * @throws Exception
     */
    public function uploadFile(string $filePath, string $purpose = 'vision'): string
    {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: {$filePath}");
        }

        $resp = Http::withToken($this->_getAssistantKey())
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post($this->baseUrlFiles, [
                'purpose' => $purpose,
            ])
            ->throw()
            ->json();

        return $resp['id'] ?? throw new Exception('uploadFile: no id returned');
    }

    /**
     * Start a run on a thread (with optional tools and response_format).
     *
     * @param string $threadId
     * @param string $assistantId
     * @param string $model
     * @param array  $tools
     * @param array|null $responseFormat e.g. ['type' => 'json_object'] or Structured Output schema
     * @return string               run_id
     */
    public function startRun(
        string $threadId,
        string $assistantId,
        string $model,
        array $tools = [],
        ?array $responseFormat = null
    ): string {
        $payload = [
            'assistant_id'    => $assistantId,
            'model'           => $model,
            'tools'           => $tools,
            'tool_choice'     => 'auto',
        ];

        if ($responseFormat) {
            $payload['response_format'] = $responseFormat;
        }

        $resp = Http::withToken($this->_getAssistantKey())
            ->withHeaders(self::ASSISTANTS_V2_HEADER)
            ->post("{$this->baseUrlThreads}/{$threadId}/runs", $payload)
            ->throw()
            ->json();

        return $resp['id'] ?? throw new Exception('startRun: no run id');
    }

    /**
     * Poll for tool calls, execute handlers, submit outputs.
     *
     * @param string $threadId
     * @param string $runId
     * @param array<string,callable> $toolHandlers
     */
    public function pollAndSubmitToolCalls(string $threadId, string $runId, array $toolHandlers = []): array
    {
        do {
            sleep(2);

            $resp = Http::withToken($this->_getAssistantKey())
                ->withHeaders(self::ASSISTANTS_V2_HEADER)
                ->get("{$this->baseUrlThreads}/{$threadId}/runs/{$runId}")
                ->throw()
                ->json();

            $status = $resp['status'] ?? 'unknown';

            if ($status === 'requires_action') {
                $toolCalls = $resp['required_action']['submit_tool_outputs']['tool_calls'] ?? [];
                $toolOutputs = [];

                foreach ($toolCalls as $call) {
                    $name = $call['function']['name'];
                    $args = json_decode($call['function']['arguments'], true);

                    Cache::put('art_recognition_status', [
                        'status' => "Executing tool: $name",
                        'tool' => $name,
                        'args' => $args,
                        'tool_call_id' => $call['id'],
                    ], now()->addMinutes(10));

                    if (isset($toolHandlers[$name]) && is_callable($toolHandlers[$name])) {
                        $output = call_user_func($toolHandlers[$name], $args);
                        $toolOutputs[] = [
                            'tool_call_id' => $call['id'],
                            'output' => json_encode($output),
                        ];
                    }
                }

                Http::withToken($this->_getAssistantKey())
                    ->withHeaders(self::ASSISTANTS_V2_HEADER)
                    ->post("{$this->baseUrlThreads}/{$threadId}/runs/{$runId}/submit_tool_outputs", [
                        'tool_outputs' => $toolOutputs,
                    ])
                    ->throw();
            }

            if (in_array($status, ['cancelled', 'expired', 'failed'], true)) {
                throw new \RuntimeException("Run failed or was cancelled: $status");
            }

        } while (($status ?? '') !== 'completed');

        return $resp;
    }



    /**
     * Poll until the run is completed, handle tool calls if needed.
     *
     * @param string $threadId
     * @param string $runId
     * @param array<string,callable> $toolHandlers
     * @param string|null $assistantId
     * @param string|null $model
     * @param array $tools
     * @param array|null $responseFormat
     * @return array
     * @throws OpenAIRunFailedException|\RuntimeException on failure or timeout
     */
    public function pollUntilRunComplete(
        string $threadId,
        string $runId,
        array $toolHandlers = [],
        ?string $assistantId = null,
        ?string $model = null,
        array $tools = [],
        ?array $responseFormat = null
    ): array
    {
        $this->pollResponse = [];
        $attempts = 0;
        $maxAttempts = 100; // Increased from 60
        $retryAttempts = 0;
        $maxRetryAttempts = 5; // Increased from 3

        do {
            sleep(3);

            $resp = Http::withToken($this->_getAssistantKey())
                ->withHeaders(self::ASSISTANTS_V2_HEADER)
                ->get("{$this->baseUrlThreads}/{$threadId}/runs/{$runId}")
                ->throw()
                ->json();

            $status = $resp['status'] ?? 'unknown';

            if ($status === 'requires_action' && !empty($toolHandlers)) {
                // Tool-Calls delegieren
                return $this->pollAndSubmitToolCalls($threadId, $runId, $toolHandlers);
            }

            Cache::put('art_recognition_status', [
                'status' => "AI Assistant Status: $status - ($attempts)",
                'run_id' => $runId,
            ], now()->addMinutes(10));

            if (in_array($status, ['failed', 'cancelled', 'expired'], true)) {
                $lastError = $resp['last_error'] ?? null;
                $errorCode = $lastError['code'] ?? null;

                $context = [
                    'run_id' => $runId,
                    'thread_id' => $threadId,
                    'assistant_id' => $resp['assistant_id'] ?? $assistantId,
                    'model' => $resp['model'] ?? $model,
                    'last_error' => $lastError,
                    'incomplete_details' => $resp['incomplete_details'] ?? null,
                    'response_payload' => $resp,
                ];

                if (in_array($errorCode, ['server_error', 'rate_limit_exceeded'], true) && $retryAttempts < $maxRetryAttempts) {
                    $retryAttempts++;
                    $backoff = match ($retryAttempts) {
                        1 => 2,
                        2 => 5,
                        3 => 10,
                        4 => 20,
                        5 => 30,
                        default => 0,
                    };

                    Log::warning("OpenAI run transient error ($errorCode), restarting run in {$backoff}s... (Attempt $retryAttempts/$maxRetryAttempts)", $context);
                    sleep($backoff);

                    // If we have the necessary info, start a NEW run instead of just polling the failed one
                    $aId = $resp['assistant_id'] ?? $assistantId;
                    $m = $resp['model'] ?? $model;

                    if ($aId && $m) {
                        try {
                            $runId = $this->startRun($threadId, $aId, $m, $tools, $responseFormat);
                            $attempts = 0; // Reset polling attempts for the new run
                            continue;
                        } catch (Exception $e) {
                            Log::error("Failed to restart OpenAI run: " . $e->getMessage(), $context);
                        }
                    }
                }

                Log::error('OpenAI run failed', $context);
                throw new OpenAIRunFailedException("OpenAI run failed with status: $status", $context);
            }

            $this->pollResponse = $resp;
            $attempts++;

        } while ($status !== 'completed' && $attempts < $maxAttempts);

        if ($status !== 'completed') {
            throw new \RuntimeException("Polling timeout: Run did not complete in time.");
        }

        return $this->pollResponse;
    }



    /**
     * Fetch and normalize all messages from a thread.
     *
     * @param  string  $threadId
     * @param  int     $limit     Anzahl der Nachrichten, die maximal abgefragt werden sollen
     * @return array<int,array{role:string,content:string}>
     */
    public function getThreadMessages(string $threadId, int $limit = 100): array
    {
        $resp = Http::withToken($this->_getAssistantKey())
            ->withHeaders(self::ASSISTANTS_V2_HEADER)
            ->get("{$this->baseUrlThreads}/{$threadId}/messages", [
                'limit' => $limit,
                'order' => 'asc',
            ])
            ->throw()
            ->json();

        $raw = $resp['data'] ?? [];

        return $raw;

        /*
        // Mappe jedes Roh-Objekt auf ['role'=>string,'content'=>string]
        return array_map(
            function (array $msg): array {
                $role = $msg['author']['role'] ?? 'assistant';
                $parts = $msg['content']['parts'] ?? [];
                $content = implode("\n", $parts);
                return ['role' => $role, 'content' => $content];
            },
            $raw
        );
        */
    }

    /**
     * Convenience: run the entire thread flow in one go.
     *
     * @param string $assistantId
     * @param array<int,array{role:string,content:string}> $messages
     * @param string $model
     * @param array  $tools
     * @param array<string,callable> $toolHandlers
     * @return array<int,array{role:string,content:string}>
     */
    public function runThread(
        string $assistantId,
        array  $messages,
        string $model,
        array  $tools = [],
        array  $toolHandlers = []
    ): array {
        $tId = $this->createThread();
        $this->appendMessageToThread($tId, $messages);
        $rId = $this->startRun($tId, $assistantId, $model, $tools);
        $this->pollUntilRunComplete($tId, $rId, $toolHandlers, $assistantId, $model, $tools);
        return $this->getThreadMessages($tId);
    }

    //==============================================================================
    // 3) Internal HTTP helper (used by completion & other)
    //==============================================================================

    /**
     * Low-level HTTP POST with retry & timeout.
     *
     * @param string $apiKey
     * @param string $url
     * @param array  $payload
     * @param int    $retries
     * @return array|null
     */
    private function sendRequest(string $apiKey, string $url, array $payload, int $retries): ?array
    {
        for ($i = 0; $i < $retries; $i++) {
            try {
                $resp = Http::timeout($this->timeout)
                    ->withHeaders([
                        'Authorization' => "Bearer {$apiKey}",
                        'Content-Type'  => 'application/json',
                    ])
                    ->post($url, $payload);

                if ($resp->successful()) {
                    return $resp->json();
                }

                Log::error('OpenAI API Error', [
                    'status' => $resp->status(),
                    'body'   => $resp->body(),
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
