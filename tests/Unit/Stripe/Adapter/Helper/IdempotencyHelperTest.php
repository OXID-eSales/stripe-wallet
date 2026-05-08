<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Adapter\Helper;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Contract\IdempotencyRecord;
use OxidEsales\Payments\Stripe\Adapter\Helper\IdempotencyHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for IdempotencyHelper shared utility.
 *
 * Sprint 46: Extracted from IdempotentStripeAdapter.
 *
 * @covers \OxidEsales\Payments\Stripe\Adapter\Helper\IdempotencyHelper
 * @group sprint-46
 * @group idempotency
 */
final class IdempotencyHelperTest extends TestCase
{
    /**
     * @test
     */
    public function createsNewRecordWhenExistingIsNull(): void
    {
        $record = IdempotencyHelper::reuseOrCreate(null, 'capture:pi_test', 'pi_test', 'capture', 86400);

        $this->assertSame('capture:pi_test', $record->getKey());
        $this->assertSame('processing', $record->getStatus());
        $this->assertNull($record->getResult());
        $this->assertFalse($record->isExpired());
    }

    /**
     * @test
     */
    public function reusesExistingRecordAndResetsStatus(): void
    {
        $existing = new IdempotencyRecord(
            'id_existing',
            'capture:pi_test',
            'pi_test',
            'capture',
            'failed',
            new DateTimeImmutable(),
            new DateTimeImmutable('+1 day')
        );
        $existing->setResult('{"error":"old error"}');

        $record = IdempotencyHelper::reuseOrCreate($existing, 'capture:pi_test', 'pi_test', 'capture', 86400);

        $this->assertSame($existing, $record);
        $this->assertSame('processing', $record->getStatus());
        $this->assertNull($record->getResult());
    }

    /**
     * @test
     */
    public function newRecordHasCorrectExpiration(): void
    {
        $before = new DateTimeImmutable();
        $record = IdempotencyHelper::reuseOrCreate(null, 'test:key', 'order1', 'test', 3600);
        $after = new DateTimeImmutable();

        $this->assertFalse($record->isExpired());
        $this->assertSame('test:key', $record->getKey());
    }

    /**
     * @test
     */
    public function newRecordHasUniqueId(): void
    {
        $record1 = IdempotencyHelper::reuseOrCreate(null, 'key1', 'order1', 'test', 3600);
        $record2 = IdempotencyHelper::reuseOrCreate(null, 'key2', 'order2', 'test', 3600);

        $this->assertNotSame($record1->getId(), $record2->getId());
    }
}
