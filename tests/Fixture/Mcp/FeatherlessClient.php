<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Fixture\Mcp;

/**
 * Minimal OpenAI-compatible client for Featherless headless inference.
 *
 * Supports tool_use (function calling) via the OpenAI chat completions API.
 * Featherless serves open-source models with OpenAI-compatible endpoints.
 */
class FeatherlessClient
{
    private string $apiUrl;
    private string $apiKey;
    private string $model;
    private int $timeout;

    public function __construct(
        string $apiUrl,
        string $apiKey,
        string $model,
        int $timeout = 120
    ) {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
        $this->model = $model;
        $this->timeout = $timeout;
    }

    /**
     * Send a chat completion request with optional tools.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param list<array<string, mixed>> $tools OpenAI-format tool definitions
     * @return array{
     *     role: string,
     *     content: string|null,
     *     tool_calls: list<array{id: string, function: array{name: string, arguments: string}}>|null,
     *     finish_reason: string
     * }
     */
    public function chatCompletion(array $messages, array $tools = []): array
    {
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'temperature' => 0.0,
        ];

        if (!empty($tools)) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }

        $response = $this->post('/chat/completions', $payload);

        $choice = $response['choices'][0] ?? [];
        $message = $choice['message'] ?? [];

        $result = [
            'role' => $message['role'] ?? 'assistant',
            'content' => $message['content'] ?? null,
            'tool_calls' => $message['tool_calls'] ?? null,
            'finish_reason' => $choice['finish_reason'] ?? 'stop',
        ];

        // DeepSeek reasoning models return reasoning_content which must be
        // sent back in the conversation history for subsequent turns.
        if (isset($message['reasoning_content'])) {
            $result['reasoning_content'] = $message['reasoning_content'];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function post(string $path, array $payload): array
    {
        $ch = curl_init($this->apiUrl . $path);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($response) || $httpCode < 200 || $httpCode >= 300) {
            throw new \RuntimeException(sprintf(
                'Featherless API error: HTTP %d — %s',
                $httpCode,
                $error ?: (is_string($response) ? $response : 'empty response')
            ));
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Featherless API returned invalid JSON');
        }

        return $decoded;
    }
}
