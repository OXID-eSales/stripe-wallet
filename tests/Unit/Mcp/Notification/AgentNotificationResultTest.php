<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Mcp\Notification;

use OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationResult;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationResult
 * @group sprint-50
 * @group mcp
 * @group notification
 */
final class AgentNotificationResultTest extends TestCase
{
    /**
     * @test
     */
    public function successFactoryCreatesDeliveredResult(): void
    {
        // Act
        $result = AgentNotificationResult::success(200);

        // Assert
        $this->assertTrue($result->isDelivered());
        $this->assertSame(200, $result->getHttpStatusCode());
        $this->assertNull($result->getErrorMessage());
    }

    /**
     * @test
     */
    public function successFactoryAcceptsDifferentStatusCodes(): void
    {
        // Act
        $result = AgentNotificationResult::success(202);

        // Assert
        $this->assertTrue($result->isDelivered());
        $this->assertSame(202, $result->getHttpStatusCode());
    }

    /**
     * @test
     */
    public function failedFactoryCreatesUndeliveredResult(): void
    {
        // Act
        $result = AgentNotificationResult::failed(500, 'Internal Server Error');

        // Assert
        $this->assertFalse($result->isDelivered());
        $this->assertSame(500, $result->getHttpStatusCode());
        $this->assertSame('Internal Server Error', $result->getErrorMessage());
    }

    /**
     * @test
     */
    public function failedFactoryWith4xxStatusCode(): void
    {
        // Act
        $result = AgentNotificationResult::failed(404, 'Not Found');

        // Assert
        $this->assertFalse($result->isDelivered());
        $this->assertSame(404, $result->getHttpStatusCode());
        $this->assertSame('Not Found', $result->getErrorMessage());
    }

    /**
     * @test
     */
    public function failedFactoryWithZeroStatusCodeForNetworkError(): void
    {
        // Act
        $result = AgentNotificationResult::failed(0, 'Connection timeout');

        // Assert
        $this->assertFalse($result->isDelivered());
        $this->assertSame(0, $result->getHttpStatusCode());
        $this->assertSame('Connection timeout', $result->getErrorMessage());
    }

    /**
     * @test
     */
    public function noCallbackFactoryCreatesUndeliveredResult(): void
    {
        // Act
        $result = AgentNotificationResult::noCallback();

        // Assert
        $this->assertFalse($result->isDelivered());
        $this->assertSame(0, $result->getHttpStatusCode());
        $this->assertSame('No callback URL registered', $result->getErrorMessage());
    }
}
