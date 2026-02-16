<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Mcp\Service\McpLogService;
use OxidEsales\Payments\Stripe\Mcp\Service\McpLogServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\Payments\Stripe\Mcp\Service\McpLogService
 */
class McpLogServiceTest extends TestCase
{
    private FileLoggerInterface&MockObject $fileLogger;
    private McpLogService $service;

    protected function setUp(): void
    {
        $this->fileLogger = $this->createMock(FileLoggerInterface::class);
        $this->service = new McpLogService($this->fileLogger);
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(McpLogServiceInterface::class, $this->service);
    }

    public function testLogRequestDelegatesToFileLogger(): void
    {
        $this->fileLogger
            ->expects($this->once())
            ->method('log')
            ->with('REQUEST', $this->callback(function (array $context): bool {
                return $context['controller'] === 'MCP'
                    && $context['client_ip'] === '1.2.3.4'
                    && $context['body_size'] === 256;
            }));

        $this->service->logRequest('MCP', [
            'client_ip' => '1.2.3.4',
            'body_size' => 256,
        ]);
    }

    public function testLogResponseDelegatesToFileLogger(): void
    {
        $this->fileLogger
            ->expects($this->once())
            ->method('log')
            ->with('RESPONSE', $this->callback(function (array $context): bool {
                return $context['controller'] === 'UCP_CHECKOUT'
                    && $context['http_status'] === 201
                    && is_array($context['response']);
            }));

        $this->service->logResponse('UCP_CHECKOUT', 201, ['status' => 'created']);
    }

    public function testLogErrorDelegatesToFileLogger(): void
    {
        $this->fileLogger
            ->expects($this->once())
            ->method('log')
            ->with('ERROR', $this->callback(function (array $context): bool {
                return $context['controller'] === 'MCP'
                    && $context['http_status'] === 401
                    && $context['error_message'] === 'Unauthorized'
                    && $context['client_ip'] === '5.6.7.8';
            }));

        $this->service->logError('MCP', 401, 'Unauthorized', [
            'client_ip' => '5.6.7.8',
        ]);
    }

    public function testLogErrorWithoutExtraData(): void
    {
        $this->fileLogger
            ->expects($this->once())
            ->method('log')
            ->with('ERROR', $this->callback(function (array $context): bool {
                return $context['controller'] === 'PRODUCT_FEED'
                    && $context['http_status'] === 500
                    && $context['error_message'] === 'Internal error';
            }));

        $this->service->logError('PRODUCT_FEED', 500, 'Internal error');
    }

    public function testLogResponseTruncatesLargePayload(): void
    {
        $largePayload = ['data' => str_repeat('x', 5000)];

        $this->fileLogger
            ->expects($this->once())
            ->method('log')
            ->with('RESPONSE', $this->callback(function (array $context): bool {
                // Response should be truncated to a string marker
                return $context['controller'] === 'MCP'
                    && $context['http_status'] === 200
                    && is_string($context['response'])
                    && str_contains($context['response'], 'truncated');
            }));

        $this->service->logResponse('MCP', 200, $largePayload);
    }

    public function testLogResponseDoesNotTruncateSmallPayload(): void
    {
        $smallPayload = ['status' => 'ok'];

        $this->fileLogger
            ->expects($this->once())
            ->method('log')
            ->with('RESPONSE', $this->callback(function (array $context): bool {
                return is_array($context['response'])
                    && $context['response']['status'] === 'ok';
            }));

        $this->service->logResponse('MCP', 200, $smallPayload);
    }

    public function testLogRequestSilentlyFailsOnException(): void
    {
        $this->fileLogger
            ->method('log')
            ->willThrowException(new \RuntimeException('Disk full'));

        // Should not throw
        $this->service->logRequest('MCP', ['test' => true]);

        $this->assertTrue(true);
    }

    public function testLogResponseSilentlyFailsOnException(): void
    {
        $this->fileLogger
            ->method('log')
            ->willThrowException(new \RuntimeException('Disk full'));

        // Should not throw
        $this->service->logResponse('MCP', 200, ['data' => 'test']);

        $this->assertTrue(true);
    }

    public function testLogErrorSilentlyFailsOnException(): void
    {
        $this->fileLogger
            ->method('log')
            ->willThrowException(new \RuntimeException('Disk full'));

        // Should not throw
        $this->service->logError('MCP', 500, 'Server error');

        $this->assertTrue(true);
    }
}
