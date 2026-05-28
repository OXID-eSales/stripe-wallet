<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Service\RequestLogService;
use OxidEsales\PaymentBase\Service\RequestLogServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for RequestLogService.
 *
 * Sprint 15: Refactored to use FileLoggerInterface instead of database model.
 * Sprint 20: Tests updated for refactored service.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\RequestLogService
 */
class RequestLogServiceTest extends TestCase
{
    private FileLoggerInterface&MockObject $fileLogger;
    private LoggerInterface&MockObject $fallbackLogger;

    protected function setUp(): void
    {
        $this->fileLogger = $this->createMock(FileLoggerInterface::class);
        $this->fallbackLogger = $this->createMock(LoggerInterface::class);
    }

    public function testImplementsInterface(): void
    {
        $service = new RequestLogService($this->fileLogger);

        $this->assertInstanceOf(RequestLogServiceInterface::class, $service);
    }

    public function testLogRequestDoesNotThrowOnSuccess(): void
    {
        $this->fileLogger->expects($this->once())
            ->method('log')
            ->with(
                'capture',
                $this->callback(function (array $context) {
                    return isset($context['reference_id'])
                        && $context['reference_id'] === 'order_123'
                        && isset($context['shop_id'])
                        && $context['shop_id'] === 1
                        && isset($context['request'])
                        && isset($context['response']);
                })
            );

        $service = new RequestLogService($this->fileLogger);

        // Act & Assert - no exception thrown
        $service->logRequest(
            action: 'capture',
            request: ['payment_intent_id' => 'pi_123'],
            response: ['capture_id' => 'ch_123', 'amount' => 1000],
            referenceId: 'order_123',
            shopId: 1
        );
        // Assertion: fileLogger->log() was called exactly once (verified by expects($this->once()) above)
    }

    public function testLogExceptionDoesNotThrowOnSuccess(): void
    {
        $this->fileLogger->expects($this->once())
            ->method('log')
            ->with(
                'capture_EXCEPTION',
                $this->callback(function (array $context) {
                    return isset($context['reference_id'])
                        && $context['reference_id'] === 'order_123'
                        && isset($context['error_message'])
                        && $context['error_message'] === 'Test error';
                })
            );

        $service = new RequestLogService($this->fileLogger);
        $exception = new \Exception('Test error', 500);

        // Act & Assert - no exception thrown
        $service->logException(
            action: 'capture',
            exception: $exception,
            referenceId: 'order_123',
            shopId: 1
        );
        // Assertion: fileLogger->log() was called exactly once (verified by expects($this->once()) above)
    }

    public function testLogRequestHandlesLoggingFailureGracefully(): void
    {
        $this->fileLogger->expects($this->once())
            ->method('log')
            ->willThrowException(new \RuntimeException('Simulated logging failure'));

        $this->fallbackLogger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Failed to log request'),
                $this->callback(function (array $context) {
                    return isset($context['action'])
                        && isset($context['reference_id'])
                        && isset($context['error']);
                })
            );

        $service = new RequestLogService($this->fileLogger, $this->fallbackLogger);

        // Act - should not throw, should log warning
        $service->logRequest(
            action: 'test',
            request: [],
            response: [],
            referenceId: 'ref_123',
            shopId: 1
        );
    }

    public function testLogExceptionHandlesLoggingFailureGracefully(): void
    {
        $this->fileLogger->expects($this->once())
            ->method('log')
            ->willThrowException(new \RuntimeException('Simulated logging failure'));

        $this->fallbackLogger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Failed to log exception'),
                $this->callback(function (array $context) {
                    return isset($context['action'])
                        && isset($context['reference_id'])
                        && isset($context['original_error'])
                        && isset($context['log_error']);
                })
            );

        $service = new RequestLogService($this->fileLogger, $this->fallbackLogger);

        // Act - should not throw
        $service->logException(
            action: 'test',
            exception: new \Exception('original error'),
            referenceId: 'ref_123',
            shopId: 1
        );
    }

    public function testLogRequestPassesActionToFileLogger(): void
    {
        $this->fileLogger->expects($this->once())
            ->method('log')
            ->with(
                'capture',
                $this->anything()
            );

        $service = new RequestLogService($this->fileLogger);

        $service->logRequest(
            action: 'capture',
            request: ['payment_intent_id' => 'pi_123'],
            response: ['capture_id' => 'ch_123'],
            referenceId: 'order_123',
            shopId: 1
        );
    }

    public function testLogExceptionUsesDefaultCode500WhenExceptionCodeIsZero(): void
    {
        $capturedContext = null;
        $this->fileLogger->expects($this->once())
            ->method('log')
            ->willReturnCallback(function (string $action, array $context) use (&$capturedContext) {
                $capturedContext = $context;
            });

        $service = new RequestLogService($this->fileLogger);

        // Test with exception that has code 0 - should default to 500
        $service->logException(
            action: 'test',
            exception: new \Exception('error', 0),
            referenceId: 'ref_123',
            shopId: 1
        );

        $this->assertEquals(500, $capturedContext['error_code']);
    }

    public function testLogExceptionUsesProvidedExceptionCode(): void
    {
        $capturedContext = null;
        $this->fileLogger->expects($this->once())
            ->method('log')
            ->willReturnCallback(function (string $action, array $context) use (&$capturedContext) {
                $capturedContext = $context;
            });

        $service = new RequestLogService($this->fileLogger);

        $service->logException(
            action: 'test',
            exception: new \Exception('error', 403),
            referenceId: 'ref_123',
            shopId: 1
        );

        $this->assertEquals(403, $capturedContext['error_code']);
    }

    public function testLogRequestPassesCorrectParameters(): void
    {
        $capturedContext = null;
        $this->fileLogger->expects($this->once())
            ->method('log')
            ->with(
                'refund',
                $this->callback(function (array $context) use (&$capturedContext) {
                    $capturedContext = $context;
                    return true;
                })
            );

        $service = new RequestLogService($this->fileLogger);

        $service->logRequest(
            action: 'refund',
            request: ['order_id' => 'order_abc'],
            response: ['refund_id' => 'refund_xyz', 'status' => 'succeeded'],
            referenceId: 'order_abc',
            shopId: 42
        );

        $this->assertEquals('order_abc', $capturedContext['reference_id']);
        $this->assertEquals(42, $capturedContext['shop_id']);
        $this->assertEquals(['order_id' => 'order_abc'], $capturedContext['request']);
        $this->assertEquals(['refund_id' => 'refund_xyz', 'status' => 'succeeded'], $capturedContext['response']);
    }

    public function testLogExceptionPassesCorrectParameters(): void
    {
        $capturedAction = null;
        $capturedContext = null;
        $this->fileLogger->expects($this->once())
            ->method('log')
            ->willReturnCallback(function (string $action, array $context) use (&$capturedAction, &$capturedContext) {
                $capturedAction = $action;
                $capturedContext = $context;
            });

        $service = new RequestLogService($this->fileLogger);

        $service->logException(
            action: 'cancel_authorization',
            exception: new \Exception('Payment not found', 404),
            referenceId: 'pi_abc123',
            shopId: 1
        );

        $this->assertEquals('cancel_authorization_EXCEPTION', $capturedAction);
        $this->assertEquals(404, $capturedContext['error_code']);
        $this->assertEquals('Payment not found', $capturedContext['error_message']);
        $this->assertEquals('pi_abc123', $capturedContext['reference_id']);
        $this->assertEquals(1, $capturedContext['shop_id']);
    }

    public function testLogRequestIncludesTimestamp(): void
    {
        $capturedContext = null;
        $this->fileLogger->expects($this->once())
            ->method('log')
            ->willReturnCallback(function (string $action, array $context) use (&$capturedContext) {
                $capturedContext = $context;
            });

        $service = new RequestLogService($this->fileLogger);

        $service->logRequest(
            action: 'test',
            request: [],
            response: [],
            referenceId: 'ref_123',
            shopId: 1
        );

        $this->assertArrayHasKey('timestamp', $capturedContext);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $capturedContext['timestamp']);
    }

    public function testLogExceptionIncludesTimestamp(): void
    {
        $capturedContext = null;
        $this->fileLogger->expects($this->once())
            ->method('log')
            ->willReturnCallback(function (string $action, array $context) use (&$capturedContext) {
                $capturedContext = $context;
            });

        $service = new RequestLogService($this->fileLogger);

        $service->logException(
            action: 'test',
            exception: new \Exception('error'),
            referenceId: 'ref_123',
            shopId: 1
        );

        $this->assertArrayHasKey('timestamp', $capturedContext);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $capturedContext['timestamp']);
    }
}
