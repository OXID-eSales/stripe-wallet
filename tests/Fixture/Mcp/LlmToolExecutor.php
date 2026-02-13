<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Fixture\Mcp;

/**
 * Executes LLM tool_calls by forwarding them as MCP JSON-RPC requests
 * to the shop's stripemcp endpoint.
 *
 * The LLM returns tool_calls in OpenAI format:
 *   { "id": "call_123", "function": { "name": "list_products", "arguments": "{...}" } }
 *
 * This class translates them to MCP JSON-RPC:
 *   { "jsonrpc": "2.0", "id": 1, "method": "tools/call", "params": { "name": "...", "arguments": {...} } }
 *
 * And returns the result as an OpenAI tool message for the next conversation turn.
 */
class LlmToolExecutor
{
    public function __construct(
        private readonly AgentTestHelper $agent
    ) {
    }

    /**
     * Execute all tool_calls from an LLM response and return tool result messages.
     *
     * @param list<array{id: string, function: array{name: string, arguments: string}}> $toolCalls
     * @return list<array{role: string, tool_call_id: string, content: string}>
     */
    public function executeAll(array $toolCalls): array
    {
        $results = [];

        foreach ($toolCalls as $toolCall) {
            $functionName = $toolCall['function']['name'] ?? '';
            $argumentsJson = $toolCall['function']['arguments'] ?? '{}';
            $callId = $toolCall['id'] ?? '';

            $arguments = json_decode($argumentsJson, true);
            if (!is_array($arguments)) {
                $arguments = [];
            }

            $mcpResponse = $this->agent->callTool($functionName, $arguments);
            $content = $mcpResponse['body']['result']['content'][0]['text']
                ?? json_encode($mcpResponse['body']);

            $results[] = [
                'role' => 'tool',
                'tool_call_id' => $callId,
                'content' => is_string($content) ? $content : json_encode($content),
            ];
        }

        return $results;
    }
}
