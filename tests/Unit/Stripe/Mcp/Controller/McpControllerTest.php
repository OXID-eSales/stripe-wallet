<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Controller;

use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\Auth\AuthResult;
use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface;
use OxidEsales\Payments\Stripe\Mcp\Controller\McpController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for McpController.
 *
 * The controller uses global functions (http_response_code, header, echo,
 * file_get_contents('php://input')), limiting what we can verify in unit tests.
 * We focus on verifying dependency injection and interaction patterns.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Controller\McpController
 */
class McpControllerTest extends TestCase
{
    private McpAuthGuardInterface&MockObject $authGuard;
    private EventDispatcherInterface&MockObject $eventDispatcher;

    protected function setUp(): void
    {
        $this->authGuard = $this->createMock(McpAuthGuardInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
    }

    private function createController(): McpController
    {
        return new McpController(
            $this->authGuard,
            $this->eventDispatcher
        );
    }

    public function testControllerCanBeConstructed(): void
    {
        $controller = $this->createController();

        $this->assertInstanceOf(McpController::class, $controller);
    }

    /**
     * When authentication fails, the controller should return early
     * without dispatching any event.
     *
     * We verify the event dispatcher is never called, which proves
     * the auth guard failure short-circuits the request handling.
     */
    public function testAuthFailureReturns401Response(): void
    {
        $authResult = AuthResult::failed('Invalid API key');
        $this->authGuard
            ->expects($this->once())
            ->method('authenticate')
            ->willReturn($authResult);

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $controller = $this->createController();

        ob_start();
        @$controller->handleRequest();
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('2.0', $decoded['jsonrpc']);
        $this->assertNull($decoded['id']);
        $this->assertSame(-32000, $decoded['error']['code']);
        $this->assertSame('Invalid API key', $decoded['error']['message']);
    }

    /**
     * When authentication succeeds, the controller reads php://input.
     * In a unit test environment php://input is empty, so the controller
     * will hit the empty-body branch before dispatching.
     *
     * We verify:
     * 1. Auth guard is called and succeeds
     * 2. Event dispatcher is NOT called (because php://input is empty in test)
     * 3. The error response indicates an empty request body
     */
    public function testValidAuthWithEmptyInputReturnsEmptyBodyError(): void
    {
        $agentContext = new AgentContext('agent_1', 'token_abc');
        $authResult = AuthResult::success($agentContext);

        $this->authGuard
            ->expects($this->once())
            ->method('authenticate')
            ->willReturn($authResult);

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $controller = $this->createController();

        ob_start();
        @$controller->handleRequest();
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('2.0', $decoded['jsonrpc']);
        $this->assertSame(-32700, $decoded['error']['code']);
        $this->assertSame('Empty request body', $decoded['error']['message']);
    }
}
