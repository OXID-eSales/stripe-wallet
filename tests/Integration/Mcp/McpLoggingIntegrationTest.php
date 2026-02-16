<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\Auth\McpAuthGuardInterface;
use OxidEsales\PaymentComponent\Mcp\Http\RateLimiterInterface;
use OxidEsales\PaymentComponent\Mcp\McpServerInterface;
use OxidEsales\PaymentComponent\Mcp\Ucp\UcpProfileInterface;
use OxidEsales\PaymentComponent\Mcp\Ucp\UcpRequestValidator;
use OxidEsales\PaymentComponent\Mcp\Ucp\UcpResponseFormatterInterface;
use OxidEsales\Payments\Stripe\Mcp\Service\McpLogService;
use OxidEsales\Payments\Stripe\Mcp\Service\McpLogServiceInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Tests\Fixture\Mcp\McpRequestBuilder;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

/**
 * Integration tests for Sprint 56: MCP Request/Response Logging.
 *
 * Verifies that the logging infrastructure is correctly wired in the
 * OXID DI container, that controller dependencies resolve, and that
 * log entries are written to disk in test mode.
 *
 * @group sprint-56
 * @group mcp-integration
 */
final class McpLoggingIntegrationTest extends TestCase
{
    private ContainerInterface $container;
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $this->container = ContainerFactory::getInstance()->getContainer();
        } catch (\Throwable $e) {
            $this->markTestSkipped('OXID container not available: ' . $e->getMessage());
        }

        $shopDir = Registry::getConfig()->getConfigParam('sShopDir');
        if (!is_string($shopDir)) {
            $this->markTestSkipped('sShopDir not configured');
        }

        $this->logFile = $shopDir . 'log/stripe/mcp.log';
    }

    // ── Service Resolution ──────────────────────────────────────────

    public function testMcpLogServiceResolvesFromContainer(): void
    {
        $service = $this->container->get(McpLogServiceInterface::class);

        $this->assertInstanceOf(McpLogServiceInterface::class, $service);
    }

    public function testMcpLogServiceIsCorrectImplementation(): void
    {
        $service = $this->container->get(McpLogServiceInterface::class);

        $this->assertInstanceOf(McpLogService::class, $service);
    }

    // ── Controller Dependency Resolution ─────────────────────────────

    /**
     * All services used by McpController::init() must resolve from the container.
     */
    public function testMcpControllerDependenciesResolveFromContainer(): void
    {
        $this->assertInstanceOf(
            McpAuthGuardInterface::class,
            $this->container->get(McpAuthGuardInterface::class)
        );
        $this->assertInstanceOf(
            EventDispatcherInterface::class,
            $this->container->get(EventDispatcherInterface::class)
        );
        $this->assertInstanceOf(
            RateLimiterInterface::class,
            $this->container->get(RateLimiterInterface::class)
        );
        $this->assertInstanceOf(
            McpLogServiceInterface::class,
            $this->container->get(McpLogServiceInterface::class)
        );
    }

    /**
     * All services used by UcpCheckoutController::init() must resolve from the container.
     */
    public function testUcpCheckoutControllerDependenciesResolveFromContainer(): void
    {
        $this->assertInstanceOf(
            McpAuthGuardInterface::class,
            $this->container->get(McpAuthGuardInterface::class)
        );
        $this->assertInstanceOf(
            UcpRequestValidator::class,
            $this->container->get(UcpRequestValidator::class)
        );
        $this->assertInstanceOf(
            UcpResponseFormatterInterface::class,
            $this->container->get(UcpResponseFormatterInterface::class)
        );
        $this->assertInstanceOf(
            EventDispatcherInterface::class,
            $this->container->get(EventDispatcherInterface::class)
        );
        $this->assertInstanceOf(
            RateLimiterInterface::class,
            $this->container->get(RateLimiterInterface::class)
        );
        $this->assertInstanceOf(
            McpLogServiceInterface::class,
            $this->container->get(McpLogServiceInterface::class)
        );
    }

    /**
     * All services used by UcpProfileController::init() must resolve from the container.
     */
    public function testUcpProfileControllerDependenciesResolveFromContainer(): void
    {
        $this->assertInstanceOf(
            UcpProfileInterface::class,
            $this->container->get(UcpProfileInterface::class)
        );
        $this->assertInstanceOf(
            RateLimiterInterface::class,
            $this->container->get(RateLimiterInterface::class)
        );
        $this->assertInstanceOf(
            McpLogServiceInterface::class,
            $this->container->get(McpLogServiceInterface::class)
        );
    }

    // ── Logging Operations (no-throw guarantee) ──────────────────────

    public function testLogRequestDoesNotThrow(): void
    {
        /** @var McpLogServiceInterface $logger */
        $logger = $this->container->get(McpLogServiceInterface::class);

        $logger->logRequest('INTEGRATION_TEST', [
            'test' => 'testLogRequestDoesNotThrow',
        ]);

        $this->addToAssertionCount(1);
    }

    public function testLogResponseDoesNotThrow(): void
    {
        /** @var McpLogServiceInterface $logger */
        $logger = $this->container->get(McpLogServiceInterface::class);

        $logger->logResponse('INTEGRATION_TEST', 200, [
            'test' => 'testLogResponseDoesNotThrow',
        ]);

        $this->addToAssertionCount(1);
    }

    public function testLogErrorDoesNotThrow(): void
    {
        /** @var McpLogServiceInterface $logger */
        $logger = $this->container->get(McpLogServiceInterface::class);

        $logger->logError('INTEGRATION_TEST', 500, 'Test error', [
            'test' => 'testLogErrorDoesNotThrow',
        ]);

        $this->addToAssertionCount(1);
    }

    // ── File Writing (test mode only) ────────────────────────────────

    public function testLogRequestWritesToFile(): void
    {
        $this->requireTestMode();

        /** @var McpLogServiceInterface $logger */
        $logger = $this->container->get(McpLogServiceInterface::class);

        $marker = 'int_req_' . uniqid('', true);
        $logger->logRequest('INTEGRATION_TEST', ['marker' => $marker]);

        $this->assertLogFileContains($marker);
    }

    public function testLogResponseWritesToFile(): void
    {
        $this->requireTestMode();

        /** @var McpLogServiceInterface $logger */
        $logger = $this->container->get(McpLogServiceInterface::class);

        $marker = 'int_resp_' . uniqid('', true);
        $logger->logResponse('INTEGRATION_TEST', 200, ['marker' => $marker]);

        $this->assertLogFileContains($marker);
    }

    public function testLogErrorWritesToFile(): void
    {
        $this->requireTestMode();

        /** @var McpLogServiceInterface $logger */
        $logger = $this->container->get(McpLogServiceInterface::class);

        $marker = 'int_err_' . uniqid('', true);
        $logger->logError('INTEGRATION_TEST', 500, $marker);

        $this->assertLogFileContains($marker);
    }

    // ── MCP Server + Logging End-to-End ──────────────────────────────

    /**
     * MCP server error response for unknown tool includes error code
     * and message. This verifies the real DI-wired server, not just unit mocks.
     */
    public function testMcpServerUnknownToolReturnsProperError(): void
    {
        /** @var McpServerInterface $server */
        $server = $this->container->get(McpServerInterface::class);
        $agentContext = new AgentContext('logging-test-agent', 'test-token');

        /** @var array{error?: array{code: int, message: string}} $response */
        $response = $server->handleJsonRpc(
            McpRequestBuilder::toolsCall('nonexistent_tool', []),
            $agentContext
        );

        $this->assertArrayHasKey('error', $response);
        $this->assertSame(-32602, $response['error']['code']);
        $this->assertStringContainsString('nonexistent_tool', $response['error']['message']);
    }

    /**
     * When a tool throws an exception, the error response includes
     * exception details in the data field for debugging.
     */
    public function testMcpServerToolExceptionIncludesDataField(): void
    {
        /** @var McpServerInterface $server */
        $server = $this->container->get(McpServerInterface::class);
        $agentContext = new AgentContext('logging-test-agent', 'test-token');

        /** @var array{error?: array{code: int, message: string, data?: array{exception_class: string, exception_message: string, tool_name: string}}} $response */
        $response = $server->handleJsonRpc(
            McpRequestBuilder::toolsCall('create_checkout', []),
            $agentContext
        );

        $this->assertArrayHasKey('error', $response);
        $this->assertSame(-32000, $response['error']['code']);
        $this->assertSame('Tool execution failed', $response['error']['message']);
        $this->assertArrayHasKey('data', $response['error']);
        $this->assertArrayHasKey('exception_class', $response['error']['data']);
        $this->assertArrayHasKey('exception_message', $response['error']['data']);
        $this->assertSame('create_checkout', $response['error']['data']['tool_name']);
    }

    /**
     * End-to-end: MCP server produces a loggable response that can be
     * passed to the log service without errors.
     */
    public function testMcpServerResponseCanBeLogged(): void
    {
        /** @var McpServerInterface $server */
        $server = $this->container->get(McpServerInterface::class);
        /** @var McpLogServiceInterface $logger */
        $logger = $this->container->get(McpLogServiceInterface::class);
        $agentContext = new AgentContext('logging-test-agent', 'test-token');

        /** @var array{result?: array<string, mixed>} $response */
        $response = $server->handleJsonRpc(
            McpRequestBuilder::toolsCall('list_products', ['limit' => 1]),
            $agentContext
        );

        // Log the response — should not throw
        $logger->logResponse('INTEGRATION_TEST', 200, $response);

        $this->assertArrayHasKey('result', $response);
    }

    // ── Helpers ───────────────────────────────────────────────────────

    private function requireTestMode(): void
    {
        /** @var ModuleConfigurationServiceInterface $config */
        $config = $this->container->get(ModuleConfigurationServiceInterface::class);
        if (!$config->isTestMode()) {
            $this->markTestSkipped('File writing tests require test mode');
        }
    }

    private function assertLogFileContains(string $marker): void
    {
        if (!file_exists($this->logFile)) {
            $this->fail('Log file does not exist: ' . $this->logFile);
        }

        $content = file_get_contents($this->logFile);
        if ($content === false) {
            $this->fail('Could not read log file: ' . $this->logFile);
        }

        $this->assertStringContainsString(
            $marker,
            $content,
            "Log file should contain the test marker: $marker"
        );
    }
}
