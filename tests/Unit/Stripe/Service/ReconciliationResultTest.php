<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Service;

use OxidSolutionCatalysts\Payments\Stripe\Service\ReconciliationResult;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ReconciliationResult DTO
 *
 * @covers \OxidSolutionCatalysts\Payments\Stripe\Service\ReconciliationResult
 * @group sprint-10
 * @group reconciliation
 */
class ReconciliationResultTest extends TestCase
{
    /**
     * @test
     */
    public function canCreateSuccessResult(): void
    {
        $result = new ReconciliationResult(
            orderId: 'order_123',
            paymentIntentId: 'pi_456',
            success: true,
            action: 'updated',
            reason: 'OXPAID updated',
            contractUpdated: true
        );

        $this->assertEquals('order_123', $result->orderId);
        $this->assertEquals('pi_456', $result->paymentIntentId);
        $this->assertTrue($result->success);
        $this->assertEquals('updated', $result->action);
        $this->assertEquals('OXPAID updated', $result->reason);
        $this->assertTrue($result->contractUpdated);
    }

    /**
     * @test
     */
    public function canCreateFailureResult(): void
    {
        $result = new ReconciliationResult(
            orderId: 'order_789',
            paymentIntentId: 'pi_000',
            success: false,
            action: 'error',
            reason: 'API Error'
        );

        $this->assertFalse($result->success);
        $this->assertEquals('error', $result->action);
        $this->assertFalse($result->contractUpdated);
    }

    /**
     * @test
     */
    public function contractUpdatedDefaultsToFalse(): void
    {
        $result = new ReconciliationResult(
            orderId: 'order_123',
            paymentIntentId: 'pi_456',
            success: true,
            action: 'updated',
            reason: 'Updated'
        );

        $this->assertFalse($result->contractUpdated);
    }

    /**
     * @test
     */
    public function toArrayReturnsCorrectStructure(): void
    {
        $result = new ReconciliationResult(
            orderId: 'order_123',
            paymentIntentId: 'pi_456',
            success: true,
            action: 'updated',
            reason: 'OXPAID updated',
            contractUpdated: true
        );

        $array = $result->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('order_123', $array['order_id']);
        $this->assertEquals('pi_456', $array['payment_intent_id']);
        $this->assertTrue($array['success']);
        $this->assertEquals('updated', $array['action']);
        $this->assertEquals('OXPAID updated', $array['reason']);
        $this->assertTrue($array['contract_updated']);
    }

    /**
     * @test
     */
    public function propertiesAreReadOnly(): void
    {
        $result = new ReconciliationResult(
            orderId: 'order_123',
            paymentIntentId: 'pi_456',
            success: true,
            action: 'updated',
            reason: 'Test'
        );

        $reflection = new \ReflectionClass($result);

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "Property {$property->getName()} should be readonly"
            );
        }
    }
}
