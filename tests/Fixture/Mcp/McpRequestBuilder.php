<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Fixture\Mcp;

class McpRequestBuilder
{
    public static function initialize(): string
    {
        return (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test-agent', 'version' => '1.0.0'],
            ],
        ]);
    }

    public static function toolsList(): string
    {
        return (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'tools/list',
            'params' => [],
        ]);
    }

    /** @param array<string, mixed> $arguments */
    public static function toolsCall(string $name, array $arguments): string
    {
        return (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'method' => 'tools/call',
            'params' => [
                'name' => $name,
                'arguments' => $arguments,
            ],
        ]);
    }

    /**
     * @param list<array{id: string, quantity: int}> $items
     * @param array<string, string> $buyer
     */
    public static function createCheckout(array $items, array $buyer = []): string
    {
        return self::toolsCall('create_checkout', [
            'items' => $items,
            'buyer' => array_merge([
                'email' => 'agent-test@example.com',
                'first_name' => 'Test',
                'last_name' => 'Agent',
            ], $buyer),
            'currency' => 'EUR',
        ]);
    }
}
