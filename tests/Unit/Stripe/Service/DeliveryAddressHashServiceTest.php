<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentComponent\Service\DeliveryAddressHashService;
use OxidEsales\PaymentComponent\Service\DeliveryAddressHashServiceInterface;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Unit tests for DeliveryAddressHashService.
 *
 * Sprint 20: Encapsulate $_REQUEST modification for delivery address hash.
 *
 */
#[CoversClass(\OxidEsales\PaymentComponent\Service\DeliveryAddressHashService::class)]
    #[Group('sprint-20')]
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

    /**
     * LSP: Service implements interface
     */
    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(
            DeliveryAddressHashServiceInterface::class,
            $this->service
        );
    }

    /**
     * SRP: Restores hash to $_REQUEST for OXID validation
     */
    public function testRestoresHashToRequest(): void
    {
        // Arrange
        $hash = 'abc123def456';

        // Act
        $this->service->restoreHashForValidation($hash);

        // Assert
        $this->assertSame($hash, $_REQUEST['sDeliveryAddressMD5']);
    }

    /**
     * Does nothing when hash is null
     */
    public function testDoesNothingWhenHashIsNull(): void
    {
        // Arrange - ensure $_REQUEST is clear
        unset($_REQUEST['sDeliveryAddressMD5']);

        // Act
        $this->service->restoreHashForValidation(null);

        // Assert - $_REQUEST should not have the key
        $this->assertArrayNotHasKey('sDeliveryAddressMD5', $_REQUEST);
    }

    /**
     * Does nothing when hash is empty string
     */
    public function testDoesNothingWhenHashIsEmpty(): void
    {
        // Arrange - ensure $_REQUEST is clear
        unset($_REQUEST['sDeliveryAddressMD5']);

        // Act
        $this->service->restoreHashForValidation('');

        // Assert - $_REQUEST should not have the key
        $this->assertArrayNotHasKey('sDeliveryAddressMD5', $_REQUEST);
    }

    /**
     * Clears hash from $_REQUEST
     */
    public function testClearsHashFromRequest(): void
    {
        // Arrange
        $_REQUEST['sDeliveryAddressMD5'] = 'some_hash';

        // Act
        $this->service->clearHash();

        // Assert
        $this->assertArrayNotHasKey('sDeliveryAddressMD5', $_REQUEST);
    }

    /**
     * Checks if hash exists in $_REQUEST
     */
    public function testChecksIfHashExists(): void
    {
        // Arrange - no hash
        unset($_REQUEST['sDeliveryAddressMD5']);
        $this->assertFalse($this->service->hasHash());

        // Arrange - has hash
        $_REQUEST['sDeliveryAddressMD5'] = 'some_hash';
        $this->assertTrue($this->service->hasHash());
    }

    /**
     * Gets hash from $_REQUEST
     */
    public function testGetsHashFromRequest(): void
    {
        // Arrange
        $expectedHash = 'abc123def456';
        $_REQUEST['sDeliveryAddressMD5'] = $expectedHash;

        // Act
        $result = $this->service->getHash();

        // Assert
        $this->assertSame($expectedHash, $result);
    }

    /**
     * Returns null when no hash in $_REQUEST
     */
    public function testReturnsNullWhenNoHash(): void
    {
        // Arrange
        unset($_REQUEST['sDeliveryAddressMD5']);

        // Act
        $result = $this->service->getHash();

        // Assert
        $this->assertNull($result);
    }
}
