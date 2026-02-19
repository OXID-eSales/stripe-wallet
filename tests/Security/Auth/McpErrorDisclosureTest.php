<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\Auth;

use OxidEsales\PaymentComponent\Mcp\AgentContextInterface;
use OxidEsales\PaymentComponent\Mcp\McpServer;
use OxidEsales\PaymentComponent\Mcp\McpToolInterface;
use PHPUnit\Framework\TestCase;

/**
 * F13: MCP Error Responses Leak Internal Details
 *
 * CRITICAL — OWASP A01:2021, PCI DSS 6.5.5
 *
 * McpServer::handleToolsCall() returns exception_class, exception_message,
 * and tool_name in JSON-RPC error responses. This reveals internal class
 * structure to unauthenticated callers.
 *
 * @group security
 * @group f13
 * @since Sprint 59
 */
class McpErrorDisclosureTest extends TestCase
{
    /**
     * F13: Error response contains exception class name (reveals namespace structure).
     */
    public function testErrorResponseContainsExceptionClassName(): void
    {
        $tool = $this->createThrowingTool(
            'test_tool',
            new \RuntimeException('Database connection failed: host=10.0.0.5')
        );

        $server = new McpServer([$tool]);
        $agentContext = $this->createMock(AgentContextInterface::class);

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'test_tool', 'arguments' => []],
        ], JSON_THROW_ON_ERROR);

        $response = $server->handleJsonRpc($request, $agentContext);

        $this->assertArrayHasKey('error', $response);

        $error = $response['error'];
        $this->assertIsArray($error);
        $this->assertArrayHasKey('data', $error);

        $data = $error['data'];
        $this->assertIsArray($data);

        // VULNERABILITY: exception class is exposed
        $this->assertArrayHasKey('exception_class', $data);
        $this->assertSame('RuntimeException', $data['exception_class']);
    }

    /**
     * F13: Error response contains exception message (may include PII/secrets).
     */
    public function testErrorResponseContainsExceptionMessage(): void
    {
        $sensitiveMessage = 'SQLSTATE[HY000]: Connection refused to mysql://root:s3cret@db:3306';
        $tool = $this->createThrowingTool(
            'test_tool',
            new \RuntimeException($sensitiveMessage)
        );

        $server = new McpServer([$tool]);
        $agentContext = $this->createMock(AgentContextInterface::class);

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'test_tool', 'arguments' => []],
        ], JSON_THROW_ON_ERROR);

        $response = $server->handleJsonRpc($request, $agentContext);

        $error = $response['error'];
        $this->assertIsArray($error);
        $data = $error['data'];
        $this->assertIsArray($data);

        // VULNERABILITY: exception message with credentials is exposed
        $this->assertArrayHasKey('exception_message', $data);
        $this->assertSame($sensitiveMessage, $data['exception_message']);
    }

    /**
     * F13: Error response contains tool name.
     */
    public function testErrorResponseContainsToolName(): void
    {
        $tool = $this->createThrowingTool(
            'internal_admin_tool',
            new \LogicException('Admin-only operation')
        );

        $server = new McpServer([$tool]);
        $agentContext = $this->createMock(AgentContextInterface::class);

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'internal_admin_tool', 'arguments' => []],
        ], JSON_THROW_ON_ERROR);

        $response = $server->handleJsonRpc($request, $agentContext);

        $error = $response['error'];
        $this->assertIsArray($error);
        $data = $error['data'];
        $this->assertIsArray($data);

        // VULNERABILITY: tool name exposed in error
        $this->assertArrayHasKey('tool_name', $data);
        $this->assertSame('internal_admin_tool', $data['tool_name']);
    }

    /**
     * F13: Custom exception with namespaced class reveals internal architecture.
     */
    public function testNamespacedExceptionRevealsArchitecture(): void
    {
        // Create a custom exception to simulate namespaced exception class name
        $exception = new \InvalidArgumentException('Validation failed');
        $tool = $this->createThrowingTool('checkout', $exception);

        $server = new McpServer([$tool]);
        $agentContext = $this->createMock(AgentContextInterface::class);

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'checkout', 'arguments' => []],
        ], JSON_THROW_ON_ERROR);

        $response = $server->handleJsonRpc($request, $agentContext);

        $error = $response['error'];
        $this->assertIsArray($error);
        $data = $error['data'];
        $this->assertIsArray($data);

        // Exception class name reveals PHP internal class structure
        $exceptionClass = $data['exception_class'];
        $this->assertIsString($exceptionClass);
        $this->assertStringContainsString('Exception', $exceptionClass);
    }

    /**
     * F13: Error data contains all three disclosure fields simultaneously.
     */
    public function testErrorDataContainsAllThreeDisclosureFields(): void
    {
        $tool = $this->createThrowingTool('my_tool', new \DomainException('State error'));

        $server = new McpServer([$tool]);
        $agentContext = $this->createMock(AgentContextInterface::class);

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'my_tool', 'arguments' => []],
        ], JSON_THROW_ON_ERROR);

        $response = $server->handleJsonRpc($request, $agentContext);

        $error = $response['error'];
        $this->assertIsArray($error);
        $data = $error['data'];
        $this->assertIsArray($data);

        // All three fields present — full information disclosure
        $this->assertCount(3, $data);
        $this->assertArrayHasKey('exception_class', $data);
        $this->assertArrayHasKey('exception_message', $data);
        $this->assertArrayHasKey('tool_name', $data);
    }

    /**
     * Positive: Unknown tool returns error without internal details.
     */
    public function testUnknownToolErrorDoesNotLeakInternals(): void
    {
        $server = new McpServer([]);
        $agentContext = $this->createMock(AgentContextInterface::class);

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'nonexistent_tool', 'arguments' => []],
        ], JSON_THROW_ON_ERROR);

        $response = $server->handleJsonRpc($request, $agentContext);

        $error = $response['error'];
        $this->assertIsArray($error);
        $this->assertSame(-32602, $error['code']);
        $this->assertArrayNotHasKey('data', $error);
    }

    /**
     * Positive: Successful tool call does not leak error structure.
     */
    public function testSuccessfulToolCallHasNoErrorData(): void
    {
        $tool = $this->createMock(McpToolInterface::class);
        $tool->method('getName')->willReturn('success_tool');
        $tool->method('execute')->willReturn(['status' => 'ok']);

        $server = new McpServer([$tool]);
        $agentContext = $this->createMock(AgentContextInterface::class);

        $request = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'success_tool', 'arguments' => []],
        ], JSON_THROW_ON_ERROR);

        $response = $server->handleJsonRpc($request, $agentContext);

        $this->assertArrayNotHasKey('error', $response);
        $this->assertArrayHasKey('result', $response);
    }

    private function createThrowingTool(string $name, \Throwable $exception): McpToolInterface
    {
        $tool = $this->createMock(McpToolInterface::class);
        $tool->method('getName')->willReturn($name);
        $tool->method('execute')->willThrowException($exception);

        return $tool;
    }
}
