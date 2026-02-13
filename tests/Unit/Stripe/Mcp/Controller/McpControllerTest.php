<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Controller;

use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\Auth\AuthResult;
use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface;
use OxidEsales\PaymentComponent\Mcp\Http\RateLimiterInterface;
use OxidEsales\Payments\Stripe\Mcp\Controller\McpController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Exception thrown by TestableMcpController::terminate() to replace exit.
 * Allows unit tests to capture the point where the controller would terminate.
 */
class McpTerminateException extends RuntimeException
{
}

/**
 * Testable subclass of McpController.
 *
 * Overrides init() to skip ContainerFactory and parent::init() (FrontendController),
 * allowing mock injection in unit tests without the full OXID framework.
 * Overrides terminate() to throw an exception instead of calling exit.
 */
class TestableMcpController extends McpController
{
    private McpAuthGuardInterface $testAuthGuard;
    private EventDispatcherInterface $testEventDispatcher;
    private RateLimiterInterface $testRateLimiter;

    public function setTestDependencies(
        McpAuthGuardInterface $authGuard,
        EventDispatcherInterface $eventDispatcher,
        RateLimiterInterface $rateLimiter
    ): void {
        $this->testAuthGuard = $authGuard;
        $this->testEventDispatcher = $eventDispatcher;
        $this->testRateLimiter = $rateLimiter;
    }

    public function init(): void
    {
        // Skip parent::init() and ContainerFactory — inject mocks via reflection
        $reflection = new ReflectionClass(McpController::class);

        $authGuardProp = $reflection->getProperty('authGuard');
        $authGuardProp->setValue($this, $this->testAuthGuard);

        $dispatcherProp = $reflection->getProperty('eventDispatcher');
        $dispatcherProp->setValue($this, $this->testEventDispatcher);

        $rateLimiterProp = $reflection->getProperty('rateLimiter');
        $rateLimiterProp->setValue($this, $this->testRateLimiter);
    }

    protected function terminate(): never
    {
        throw new McpTerminateException('Controller terminated');
    }
}

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
    private RateLimiterInterface&MockObject $rateLimiter;

    protected function setUp(): void
    {
        $this->authGuard = $this->createMock(McpAuthGuardInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);

        // Default: rate limiter allows requests
        $this->rateLimiter->method('isAllowed')->willReturn(true);
    }

    private function createController(): TestableMcpController
    {
        $controller = new TestableMcpController();
        $controller->setTestDependencies(
            $this->authGuard,
            $this->eventDispatcher,
            $this->rateLimiter
        );
        $controller->init();

        return $controller;
    }

    /**
     * Calls render() and captures echo output, catching the terminate exception.
     *
     * @return string The captured output
     */
    private function callRenderAndCapture(TestableMcpController $controller): string
    {
        ob_start();
        try {
            @$controller->render();
        } catch (McpTerminateException) {
            // Expected — controller called terminate() instead of exit
        }

        return (string) ob_get_clean();
    }

    public function testControllerCanBeConstructed(): void
    {
        $controller = $this->createController();

        $this->assertInstanceOf(McpController::class, $controller);
    }

    /**
     * When rate limit is exceeded, the controller returns 429
     * without checking auth or dispatching events.
     */
    public function testRateLimitExceededReturns429(): void
    {
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);
        $this->rateLimiter->method('isAllowed')->willReturn(false);

        $this->authGuard
            ->expects($this->never())
            ->method('authenticate');

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $controller = $this->createController();
        $output = $this->callRenderAndCapture($controller);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('2.0', $decoded['jsonrpc']);
        $this->assertSame(-32000, $decoded['error']['code']);
        $this->assertSame('Too many requests', $decoded['error']['message']);
    }

    /**
     * When authentication fails, the controller should return early
     * without dispatching any event.
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
        $output = $this->callRenderAndCapture($controller);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('2.0', $decoded['jsonrpc']);
        $this->assertNull($decoded['id']);
        $this->assertSame(-32000, $decoded['error']['code']);
        $this->assertSame('Invalid API key', $decoded['error']['message']);
    }

    /**
     * When authentication succeeds but Content-Type is not application/json,
     * the controller rejects with 415 before reading the body.
     *
     * In a unit test environment, CONTENT_TYPE is not set, so the controller
     * will reject with 415 (missing Content-Type = not application/json).
     */
    public function testMissingContentTypeReturns415(): void
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
        $output = $this->callRenderAndCapture($controller);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertSame('2.0', $decoded['jsonrpc']);
        $this->assertSame(-32700, $decoded['error']['code']);
        $this->assertStringContainsString('Content-Type', $decoded['error']['message']);
    }
}
