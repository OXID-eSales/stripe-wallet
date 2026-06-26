<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Service\DeliveryAddressHashService;
use OxidEsales\PaymentBase\Service\DeliveryAddressHashServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DeliveryAddressHashService.
 *
 * Sprint 20: Encapsulate $_REQUEST modification for delivery address hash.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\PaymentBase\Service\DeliveryAddressHashService::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-20')]
final class DeliveryAddressHashServiceTest extends TestCase
{
    private DeliveryAddressHashService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Clear any previous state
        unset($_REQUEST['sDeliveryAddressMD5']);

        $this->service = new DeliveryAddressHashService();
    }

    protected function tearDown(): void
    {
        // Clean up $_REQUEST
        unset($_REQUEST['sDeliveryAddressMD5']);

        parent::tearDown();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function implementsInterface(): void
    {
        $this->assertInstanceOf(
            DeliveryAddressHashServiceInterface::class,
            $this->service
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function restoresHashToRequest(): void
    {
        // Arrange
        $hash = 'abc123def456';

        // Act
        $this->service->restoreHashForValidation($hash);

        // Assert
        $this->assertSame($hash, $_REQUEST['sDeliveryAddressMD5']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function doesNothingWhenHashIsNull(): void
    {
        // Arrange - ensure $_REQUEST is clear
        unset($_REQUEST['sDeliveryAddressMD5']);

        // Act
        $this->service->restoreHashForValidation(null);

        // Assert - $_REQUEST should not have the key
        $this->assertArrayNotHasKey('sDeliveryAddressMD5', $_REQUEST);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function doesNothingWhenHashIsEmpty(): void
    {
        // Arrange - ensure $_REQUEST is clear
        unset($_REQUEST['sDeliveryAddressMD5']);

        // Act
        $this->service->restoreHashForValidation('');

        // Assert - $_REQUEST should not have the key
        $this->assertArrayNotHasKey('sDeliveryAddressMD5', $_REQUEST);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function clearsHashFromRequest(): void
    {
        // Arrange
        $_REQUEST['sDeliveryAddressMD5'] = 'some_hash';

        // Act
        $this->service->clearHash();

        // Assert
        $this->assertArrayNotHasKey('sDeliveryAddressMD5', $_REQUEST);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function checksIfHashExists(): void
    {
        // Arrange - no hash
        unset($_REQUEST['sDeliveryAddressMD5']);
        $this->assertFalse($this->service->hasHash());

        // Arrange - has hash
        $_REQUEST['sDeliveryAddressMD5'] = 'some_hash';
        $this->assertTrue($this->service->hasHash());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function getsHashFromRequest(): void
    {
        // Arrange
        $expectedHash = 'abc123def456';
        $_REQUEST['sDeliveryAddressMD5'] = $expectedHash;

        // Act
        $result = $this->service->getHash();

        // Assert
        $this->assertSame($expectedHash, $result);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function returnsNullWhenNoHash(): void
    {
        // Arrange
        unset($_REQUEST['sDeliveryAddressMD5']);

        // Act
        $result = $this->service->getHash();

        // Assert
        $this->assertNull($result);
    }
}
