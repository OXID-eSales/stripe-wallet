<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Controller;

use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\Auth\AuthResult;
use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface;
use OxidEsales\PaymentComponent\Mcp\Http\RateLimiterInterface;
use OxidEsales\PaymentComponent\Mcp\Ucp\UcpRequestValidator;
use OxidEsales\PaymentComponent\Mcp\Ucp\UcpResponseFormatterInterface;
use OxidEsales\Payments\Stripe\Mcp\Controller\UcpCheckoutController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

/**
 * Exception thrown by TestableUcpCheckoutController::terminate() to replace exit.
 * Allows unit tests to capture the point where the controller would terminate.
 */
class UcpCheckoutTerminateException extends RuntimeException
{
}

/**
 * Testable subclass of UcpCheckoutController.
 *
 * Overrides init() to skip ContainerFactory and parent::init() (FrontendController),
 * allowing mock injection in unit tests without the full OXID framework.
 * Overrides terminate() to throw an exception instead of calling exit.
 */
class TestableUcpCheckoutController extends UcpCheckoutController
{
    private McpAuthGuardInterface $testAuthGuard;
    private UcpRequestValidator $testRequestValidator;
    private UcpResponseFormatterInterface $testResponseFormatter;
    private EventDispatcherInterface $testEventDispatcher;
    private RateLimiterInterface $testRateLimiter;

    public function setTestDependencies(
        McpAuthGuardInterface $authGuard,
        UcpRequestValidator $requestValidator,
        UcpResponseFormatterInterface $responseFormatter,
        EventDispatcherInterface $eventDispatcher,
        RateLimiterInterface $rateLimiter
    ): void {
        $this->testAuthGuard = $authGuard;
        $this->testRequestValidator = $requestValidator;
        $this->testResponseFormatter = $responseFormatter;
        $this->testEventDispatcher = $eventDispatcher;
        $this->testRateLimiter = $rateLimiter;
    }

    public function init(): void
    {
        // Skip parent::init() and ContainerFactory — inject mocks via reflection
        $reflection = new ReflectionClass(UcpCheckoutController::class);

        $authGuardProp = $reflection->getProperty('authGuard');
        $authGuardProp->setValue($this, $this->testAuthGuard);

        $validatorProp = $reflection->getProperty('requestValidator');
        $validatorProp->setValue($this, $this->testRequestValidator);

        $formatterProp = $reflection->getProperty('responseFormatter');
        $formatterProp->setValue($this, $this->testResponseFormatter);

        $dispatcherProp = $reflection->getProperty('eventDispatcher');
        $dispatcherProp->setValue($this, $this->testEventDispatcher);

        $rateLimiterProp = $reflection->getProperty('rateLimiter');
        $rateLimiterProp->setValue($this, $this->testRateLimiter);
    }

    protected function terminate(): never
    {
        throw new UcpCheckoutTerminateException('Controller terminated');
    }
}

/**
 * Unit tests for UcpCheckoutController.
 *
 * The controller uses global functions (http_response_code, header, echo,
 * file_get_contents('php://input'), $_SERVER), limiting what we can verify
 * in unit tests. We focus on verifying dependency interaction patterns.
 *
 * @covers \OxidEsales\Payments\Stripe\Mcp\Controller\UcpCheckoutController
 */
class UcpCheckoutControllerTest extends TestCase
{
    private McpAuthGuardInterface&MockObject $authGuard;
    private UcpRequestValidator&MockObject $requestValidator;
    private UcpResponseFormatterInterface&MockObject $responseFormatter;
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private RateLimiterInterface&MockObject $rateLimiter;

    /** @var array<string, mixed> */
    private array $originalServer = [];

    protected function setUp(): void
    {
        $this->authGuard = $this->createMock(McpAuthGuardInterface::class);
        $this->requestValidator = $this->createMock(UcpRequestValidator::class);
        $this->responseFormatter = $this->createMock(UcpResponseFormatterInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->rateLimiter = $this->createMock(RateLimiterInterface::class);

        // Default: rate limiter allows requests
        $this->rateLimiter->method('isAllowed')->willReturn(true);

        // Default: response formatter returns structured errors
        $this->responseFormatter
            ->method('formatError')
            ->willReturnCallback(function (string $type, string $message): array {
                return ['error' => ['type' => $type, 'message' => $message]];
            });

        // Save original $_SERVER keys we might modify
        $this->originalServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->originalServer;
    }

    private function createController(): TestableUcpCheckoutController
    {
        $controller = new TestableUcpCheckoutController();
        $controller->setTestDependencies(
            $this->authGuard,
            $this->requestValidator,
            $this->responseFormatter,
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
    private function callRenderAndCapture(TestableUcpCheckoutController $controller): string
    {
        ob_start();
        try {
            @$controller->render();
        } catch (UcpCheckoutTerminateException) {
            // Expected — controller called terminate() instead of exit
        }

        return (string) ob_get_clean();
    }

    public function testControllerCanBeConstructed(): void
    {
        $controller = $this->createController();

        $this->assertInstanceOf(UcpCheckoutController::class, $controller);
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
        $this->assertArrayHasKey('error', $decoded);
        $this->assertSame('rate_limit_exceeded', $decoded['error']['type']);
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
        $this->assertArrayHasKey('error', $decoded);
        $this->assertSame('authentication_error', $decoded['error']['type']);
        $this->assertSame('Invalid API key', $decoded['error']['message']);
    }

    /**
     * When authentication succeeds but required headers are missing,
     * the controller rejects with 400.
     */
    public function testMissingHeadersReturns400(): void
    {
        $agentContext = new AgentContext('agent_1', 'token_abc');
        $authResult = AuthResult::success($agentContext);

        $this->authGuard
            ->expects($this->once())
            ->method('authenticate')
            ->willReturn($authResult);

        $this->requestValidator
            ->expects($this->once())
            ->method('validateHeaders')
            ->willReturn([
                'valid' => false,
                'errors' => ['Missing required header: Request-Id'],
            ]);

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $controller = $this->createController();
        $output = $this->callRenderAndCapture($controller);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertSame('invalid_request', $decoded['error']['type']);
        $this->assertStringContainsString('Request-Id', $decoded['error']['message']);
    }

    /**
     * When auth and headers pass but Content-Type is not application/json
     * on a POST request, the controller rejects with 415.
     */
    public function testPostWithoutJsonContentTypeReturns415(): void
    {
        $agentContext = new AgentContext('agent_1', 'token_abc');
        $authResult = AuthResult::success($agentContext);

        $this->authGuard
            ->expects($this->once())
            ->method('authenticate')
            ->willReturn($authResult);

        $this->requestValidator
            ->method('validateHeaders')
            ->willReturn(['valid' => true, 'errors' => []]);

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        // Simulate a POST request without JSON Content-Type
        $_SERVER['REQUEST_METHOD'] = 'POST';
        unset($_SERVER['CONTENT_TYPE'], $_SERVER['HTTP_CONTENT_TYPE']);

        $controller = $this->createController();
        $output = $this->callRenderAndCapture($controller);

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertSame('invalid_request', $decoded['error']['type']);
        $this->assertStringContainsString('Content-Type', $decoded['error']['message']);
    }

    /**
     * When auth succeeds and headers validate, a GET request dispatches
     * the UcpCheckoutRequestEvent without reading a body.
     */
    public function testGetRequestDispatchesEventWithoutBody(): void
    {
        $agentContext = new AgentContext('agent_1', 'token_abc');
        $authResult = AuthResult::success($agentContext);

        $this->authGuard
            ->expects($this->once())
            ->method('authenticate')
            ->willReturn($authResult);

        $this->requestValidator
            ->method('validateHeaders')
            ->willReturn(['valid' => true, 'errors' => []]);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch');

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_REQUEST_ID'] = 'req_123';

        $controller = $this->createController();
        $output = $this->callRenderAndCapture($controller);

        // Default response when no handler sets data
        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertSame('internal_error', $decoded['error']['type']);
        $this->assertStringContainsString('No handler', $decoded['error']['message']);
    }
}
