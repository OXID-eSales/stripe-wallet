<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Tests\Unit\Stripe\Service;

use OxidEsales\Payments\Stripe\Service\RequestLogService;
use OxidEsales\Payments\Stripe\Service\RequestLogServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Unit tests for RequestLogService.
 *
 * Sprint 8: Facade pattern wrapping legacy RequestLog model.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\RequestLogService
 */
class RequestLogServiceTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testImplementsInterface(): void
    {
        $service = new RequestLogService(new NullLogger());

        $this->assertInstanceOf(RequestLogServiceInterface::class, $service);
    }

    public function testCanBeConstructedWithNullLogger(): void
    {
        // Should not throw - NullLogger is used by default
        $service = new RequestLogService();

        $this->assertInstanceOf(RequestLogService::class, $service);
    }

    public function testLogRequestDoesNotThrowOnSuccess(): void
    {
        $mockRequestLog = $this->createMockRequestLog();
        $mockRequestLog->expects($this->once())
            ->method('logRequest');

        $service = new RequestLogService(new NullLogger(), fn () => $mockRequestLog);

        // Act & Assert - no exception thrown
        $service->logRequest(
            action: 'capture',
            request: ['payment_intent_id' => 'pi_123'],
            response: ['capture_id' => 'ch_123', 'amount' => 1000],
            referenceId: 'order_123',
            shopId: 1
        );

        $this->assertTrue(true);
    }

    public function testLogExceptionDoesNotThrowOnSuccess(): void
    {
        $mockRequestLog = $this->createMockRequestLog();
        $mockRequestLog->expects($this->once())
            ->method('logExceptionResponse');

        $service = new RequestLogService(new NullLogger(), fn () => $mockRequestLog);
        $exception = new \Exception('Test error', 500);

        // Act & Assert - no exception thrown
        $service->logException(
            action: 'capture',
            exception: $exception,
            referenceId: 'order_123',
            shopId: 1
        );

        $this->assertTrue(true);
    }

    public function testLogRequestHandlesLoggingFailureGracefully(): void
    {
        $mockRequestLog = $this->createMockRequestLog();
        $mockRequestLog->expects($this->once())
            ->method('logRequest')
            ->willThrowException(new \RuntimeException('Simulated logging failure'));

        $this->logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Failed to log request'),
                $this->callback(function (array $context) {
                    return isset($context['action'])
                        && isset($context['reference_id'])
                        && isset($context['error']);
                })
            );

        $service = new RequestLogService($this->logger, fn () => $mockRequestLog);

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
        $mockRequestLog = $this->createMockRequestLog();
        $mockRequestLog->expects($this->once())
            ->method('logExceptionResponse')
            ->willThrowException(new \RuntimeException('Simulated logging failure'));

        $this->logger->expects($this->once())
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

        $service = new RequestLogService($this->logger, fn () => $mockRequestLog);

        // Act - should not throw
        $service->logException(
            action: 'test',
            exception: new \Exception('original error'),
            referenceId: 'ref_123',
            shopId: 1
        );
    }

    public function testLogRequestMergesActionIntoRequest(): void
    {
        $capturedRequest = null;
        $mockRequestLog = $this->createMockRequestLog();
        $mockRequestLog->expects($this->once())
            ->method('logRequest')
            ->willReturnCallback(function (array $request) use (&$capturedRequest) {
                $capturedRequest = $request;
            });

        $service = new RequestLogService(new NullLogger(), fn () => $mockRequestLog);

        $service->logRequest(
            action: 'capture',
            request: ['payment_intent_id' => 'pi_123'],
            response: ['capture_id' => 'ch_123'],
            referenceId: 'order_123',
            shopId: 1
        );

        $this->assertIsArray($capturedRequest);
        $this->assertArrayHasKey('action', $capturedRequest);
        $this->assertEquals('capture', $capturedRequest['action']);
        $this->assertArrayHasKey('payment_intent_id', $capturedRequest);
    }

    public function testLogExceptionUsesExceptionCodeOrDefault500(): void
    {
        $capturedCode = null;
        $mockRequestLog = $this->createMockRequestLog();
        $mockRequestLog->expects($this->once())
            ->method('logExceptionResponse')
            ->willReturnCallback(function (array $request, int $code) use (&$capturedCode) {
                $capturedCode = $code;
            });

        $service = new RequestLogService(new NullLogger(), fn () => $mockRequestLog);

        // Test with exception that has code 0 - should default to 500
        $service->logException(
            action: 'test',
            exception: new \Exception('error', 0),
            referenceId: 'ref_123',
            shopId: 1
        );

        $this->assertEquals(500, $capturedCode);
    }

    public function testLogExceptionUsesProvidedExceptionCode(): void
    {
        $capturedCode = null;
        $mockRequestLog = $this->createMockRequestLog();
        $mockRequestLog->expects($this->once())
            ->method('logExceptionResponse')
            ->willReturnCallback(function (array $request, int $code) use (&$capturedCode) {
                $capturedCode = $code;
            });

        $service = new RequestLogService(new NullLogger(), fn () => $mockRequestLog);

        $service->logException(
            action: 'test',
            exception: new \Exception('error', 403),
            referenceId: 'ref_123',
            shopId: 1
        );

        $this->assertEquals(403, $capturedCode);
    }

    public function testLogRequestPassesCorrectParametersToRequestLog(): void
    {
        $capturedParams = [];
        $mockRequestLog = $this->createMockRequestLog();
        $mockRequestLog->expects($this->once())
            ->method('logRequest')
            ->willReturnCallback(function (
                array $request,
                array $response,
                string $referenceId,
                int $shopId
            ) use (&$capturedParams) {
                $capturedParams = [
                    'request' => $request,
                    'response' => $response,
                    'referenceId' => $referenceId,
                    'shopId' => $shopId,
                ];
            });

        $service = new RequestLogService(new NullLogger(), fn () => $mockRequestLog);

        $service->logRequest(
            action: 'refund',
            request: ['order_id' => 'order_abc'],
            response: ['refund_id' => 'refund_xyz', 'status' => 'succeeded'],
            referenceId: 'order_abc',
            shopId: 42
        );

        $this->assertEquals('order_abc', $capturedParams['referenceId']);
        $this->assertEquals(42, $capturedParams['shopId']);
        $this->assertEquals('refund', $capturedParams['request']['action']);
        $this->assertEquals('order_abc', $capturedParams['request']['order_id']);
        $this->assertEquals('refund_xyz', $capturedParams['response']['refund_id']);
    }

    public function testLogExceptionPassesCorrectParametersToRequestLog(): void
    {
        $capturedParams = [];
        $mockRequestLog = $this->createMockRequestLog();
        $mockRequestLog->expects($this->once())
            ->method('logExceptionResponse')
            ->willReturnCallback(function (
                array $request,
                int $code,
                string $message,
                string $action,
                string $referenceId
            ) use (&$capturedParams) {
                $capturedParams = [
                    'request' => $request,
                    'code' => $code,
                    'message' => $message,
                    'action' => $action,
                    'referenceId' => $referenceId,
                ];
            });

        $service = new RequestLogService(new NullLogger(), fn () => $mockRequestLog);

        $service->logException(
            action: 'cancel_authorization',
            exception: new \Exception('Payment not found', 404),
            referenceId: 'pi_abc123',
            shopId: 1
        );

        $this->assertEquals('cancel_authorization', $capturedParams['action']);
        $this->assertEquals('cancel_authorization', $capturedParams['request']['action']);
        $this->assertEquals(404, $capturedParams['code']);
        $this->assertEquals('Payment not found', $capturedParams['message']);
        $this->assertEquals('pi_abc123', $capturedParams['referenceId']);
    }

    /**
     * Create a mock RequestLog object.
     *
     * @return MockObject
     */
    private function createMockRequestLog(): MockObject
    {
        $mock = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['logRequest', 'logExceptionResponse'])
            ->getMock();

        return $mock;
    }
}
