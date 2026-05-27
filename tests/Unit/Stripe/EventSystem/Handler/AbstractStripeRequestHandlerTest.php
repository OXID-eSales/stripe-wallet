<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\EventSystem\Handler;

use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\EventSystem\Handler\AbstractStripeRequestHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AbstractStripeRequestHandler.
 *
 * Sprint 114.8: Verifies the shared logEvent() plumbing is invoked by concrete handlers.
 * Parity net: all concrete handler tests remain the primary behavior gate.
 *
 * @covers \OxidEsales\Payments\Stripe\EventSystem\Handler\AbstractStripeRequestHandler
 * @group sprint-114-8
 */
final class AbstractStripeRequestHandlerTest extends TestCase
{
    /**
     * @test
     * logEvent() delegates to the injected FileLoggerInterface when present.
     */
    public function logEventDelegatesToFileLoggerWhenProvided(): void
    {
        $logger = $this->createMock(FileLoggerInterface::class);
        $logger->expects($this->once())
            ->method('log')
            ->with('test message', ['key' => 'val']);

        $handler = new TestableAbstractHandler($logger);
        $handler->exposedLogEvent('test message', ['key' => 'val']);
    }

    /**
     * @test
     * logEvent() is a no-op when no FileLoggerInterface is injected.
     */
    public function logEventIsNoOpWhenNoLoggerProvided(): void
    {
        $handler = new TestableAbstractHandler(null);
        // Should not throw
        $handler->exposedLogEvent('test message');
        $this->assertTrue(true);
    }
}

/**
 * Minimal concrete subclass for testing AbstractStripeRequestHandler.
 * Only exposes logEvent() — no other behavior.
 */
final class TestableAbstractHandler extends AbstractStripeRequestHandler
{
    public function __construct(?FileLoggerInterface $eventLogger)
    {
        $this->eventLogger = $eventLogger;
    }

    public function exposedLogEvent(string $message, array $context = []): void
    {
        $this->logEvent($message, $context);
    }

    public static function getHandledEventClass(): string
    {
        return \stdClass::class;
    }

    public function getPriority(): int
    {
        return 0;
    }

    public function handle(object $event): void
    {
    }
}
