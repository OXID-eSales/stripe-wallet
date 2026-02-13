<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Mcp\Notification;

use OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationPayload;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidEsales\PaymentComponent\Mcp\Notification\AgentNotificationPayload
 * @group sprint-50
 * @group mcp
 * @group notification
 */
final class AgentNotificationPayloadTest extends TestCase
{
    /**
     * @test
     */
    public function toArrayReturnsRequiredFields(): void
    {
        // Arrange
        $payload = new AgentNotificationPayload(
            'order.created',
            'contract_123',
            'created'
        );

        // Act
        $array = $payload->toArray();

        // Assert
        $this->assertSame('order.created', $array['event_type']);
        $this->assertSame('contract_123', $array['checkout_session_id']);
        $this->assertSame('created', $array['status']);
        $this->assertIsInt($array['timestamp']);
        $this->assertArrayNotHasKey('order', $array);
        $this->assertArrayNotHasKey('metadata', $array);
    }

    /**
     * @test
     */
    public function toArrayIncludesOrderDataWhenProvided(): void
    {
        // Arrange
        $payload = new AgentNotificationPayload(
            'order.created',
            'contract_456',
            'created',
            'order_789',
            'https://shop.example.com/orders/789'
        );

        // Act
        $array = $payload->toArray();

        // Assert
        $this->assertArrayHasKey('order', $array);
        $this->assertSame('order_789', $array['order']['id']);
        $this->assertSame('https://shop.example.com/orders/789', $array['order']['permalink_url']);
    }

    /**
     * @test
     */
    public function toArrayExcludesOrderDataWhenNull(): void
    {
        // Arrange
        $payload = new AgentNotificationPayload(
            'order.canceled',
            'contract_abc',
            'canceled'
        );

        // Act
        $array = $payload->toArray();

        // Assert
        $this->assertArrayNotHasKey('order', $array);
    }

    /**
     * @test
     */
    public function toArrayIncludesMetadataWhenProvided(): void
    {
        // Arrange
        $metadata = ['agent_id' => 'agent_001', 'session_id' => 'sess_xyz'];
        $payload = new AgentNotificationPayload(
            'order.fulfilled',
            'contract_def',
            'fulfilled',
            'order_ghi',
            null,
            $metadata
        );

        // Act
        $array = $payload->toArray();

        // Assert
        $this->assertArrayHasKey('metadata', $array);
        $this->assertSame('agent_001', $array['metadata']['agent_id']);
        $this->assertSame('sess_xyz', $array['metadata']['session_id']);
    }

    /**
     * @test
     */
    public function toArrayExcludesMetadataWhenEmpty(): void
    {
        // Arrange
        $payload = new AgentNotificationPayload(
            'order.created',
            'contract_jkl',
            'created',
            null,
            null,
            []
        );

        // Act
        $array = $payload->toArray();

        // Assert
        $this->assertArrayNotHasKey('metadata', $array);
    }

    /**
     * @test
     */
    public function toJsonReturnsValidJsonString(): void
    {
        // Arrange
        $payload = new AgentNotificationPayload(
            'order.created',
            'contract_mno',
            'created',
            'order_pqr'
        );

        // Act
        $json = $payload->toJson();

        // Assert
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        $this->assertSame('order.created', $decoded['event_type']);
        $this->assertSame('contract_mno', $decoded['checkout_session_id']);
        $this->assertSame('created', $decoded['status']);
        $this->assertSame('order_pqr', $decoded['order']['id']);
    }

    /**
     * @test
     */
    public function toJsonHandlesUnicodeCorrectly(): void
    {
        // Arrange
        $metadata = ['note' => 'Bestellung mit Umlaut'];
        $payload = new AgentNotificationPayload(
            'order.created',
            'contract_uni',
            'created',
            null,
            null,
            $metadata
        );

        // Act
        $json = $payload->toJson();

        // Assert - JSON_UNESCAPED_UNICODE should preserve umlauts
        $this->assertStringContainsString('Umlaut', $json);
        $decoded = json_decode($json, true);
        $this->assertSame('Bestellung mit Umlaut', $decoded['metadata']['note']);
    }

    /**
     * @test
     */
    public function getEventTypeReturnsCorrectValue(): void
    {
        // Arrange
        $payload = new AgentNotificationPayload(
            'order.fulfilled',
            'contract_evt',
            'fulfilled'
        );

        // Act & Assert
        $this->assertSame('order.fulfilled', $payload->getEventType());
    }

    /**
     * @test
     */
    public function toArrayWithOrderAndMetadataIncludesBoth(): void
    {
        // Arrange
        $payload = new AgentNotificationPayload(
            'order.created',
            'contract_full',
            'created',
            'order_full',
            'https://shop.example.com/orders/full',
            ['agent_id' => 'agent_full']
        );

        // Act
        $array = $payload->toArray();

        // Assert
        $this->assertArrayHasKey('order', $array);
        $this->assertArrayHasKey('metadata', $array);
        $this->assertSame('order_full', $array['order']['id']);
        $this->assertSame('agent_full', $array['metadata']['agent_id']);
    }
}
