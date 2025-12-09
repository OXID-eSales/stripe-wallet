<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\Service;

use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Stripe\Service\ContractMetadataService;
use OxidSolutionCatalysts\Payments\Stripe\Service\ContractMetadataServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * TDD Tests for ContractMetadataService.
 *
 * Sprint 21: Extract business logic from StripeContractCreationHandler.
 */
class ContractMetadataServiceTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear session before each test
        $_SESSION = [];
        unset($_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_USER_AGENT']);
    }

    protected function tearDown(): void
    {
        // Clean up after tests
        $_SESSION = [];
    }

    private function createService(): ContractMetadataService
    {
        return new ContractMetadataService();
    }

    // --- Service Interface Tests ---

    public function testServiceImplementsInterface(): void
    {
        $service = $this->createService();

        $this->assertInstanceOf(ContractMetadataServiceInterface::class, $service);
    }

    // --- storeDeliveryAddressMetadata Tests ---

    public function testStoreDeliveryAddressMetadataFromSession(): void
    {
        // Arrange
        $contract = $this->createMock(PaymentContractInterface::class);
        $basket = new \stdClass();

        // Set up session with address hash
        $_SESSION['sDelAddrMD5'] = 'session_hash_abc123';
        $_SESSION['deladrid'] = 'deladdr_456';

        // Expect metadata to be set
        $metadataSet = [];
        $contract->expects($this->atLeast(2))
            ->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        // Act
        $service = $this->createService();
        $service->storeDeliveryAddressMetadata($contract, $basket);

        // Assert
        $this->assertArrayHasKey('delivery_address_hash', $metadataSet);
        $this->assertEquals('session_hash_abc123', $metadataSet['delivery_address_hash']);
        $this->assertArrayHasKey('delivery_address_id', $metadataSet);
        $this->assertEquals('deladdr_456', $metadataSet['delivery_address_id']);
    }

    public function testStoreDeliveryAddressMetadataOnlyHashNoId(): void
    {
        // Arrange
        $contract = $this->createMock(PaymentContractInterface::class);
        $basket = new \stdClass();

        // Set up session with only hash
        $_SESSION['sDelAddrMD5'] = 'only_hash_xyz';
        // No deladrid

        $metadataSet = [];
        $contract->expects($this->once())
            ->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        // Act
        $service = $this->createService();
        $service->storeDeliveryAddressMetadata($contract, $basket);

        // Assert
        $this->assertArrayHasKey('delivery_address_hash', $metadataSet);
        $this->assertEquals('only_hash_xyz', $metadataSet['delivery_address_hash']);
        $this->assertArrayNotHasKey('delivery_address_id', $metadataSet);
    }

    public function testStoreDeliveryAddressMetadataSkipsEmptyValues(): void
    {
        // Arrange
        $contract = $this->createMock(PaymentContractInterface::class);
        $basket = new \stdClass(); // Plain object with no methods

        // Clear session
        $_SESSION = [];

        // Should not set empty metadata
        $contract->expects($this->never())
            ->method('setMetadata');

        // Act
        $service = $this->createService();
        $service->storeDeliveryAddressMetadata($contract, $basket);
    }

    // --- storeSecurityMetadata Tests ---

    public function testStoreSecurityMetadataStoresUserIp(): void
    {
        // Arrange
        $contract = $this->createMock(PaymentContractInterface::class);
        $context = new EventContext([]);

        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';

        $metadataSet = [];
        $contract->expects($this->atLeastOnce())
            ->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        // Act
        $service = $this->createService();
        $service->storeSecurityMetadata($contract, $context);

        // Assert
        $this->assertArrayHasKey('user_ip', $metadataSet);
        $this->assertEquals('192.168.1.100', $metadataSet['user_ip']);
    }

    public function testStoreSecurityMetadataStoresUserAgent(): void
    {
        // Arrange
        $contract = $this->createMock(PaymentContractInterface::class);
        $context = new EventContext([]);

        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test Browser';

        $metadataSet = [];
        $contract->expects($this->atLeastOnce())
            ->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        // Act
        $service = $this->createService();
        $service->storeSecurityMetadata($contract, $context);

        // Assert
        $this->assertArrayHasKey('user_agent', $metadataSet);
        $this->assertEquals('Mozilla/5.0 Test Browser', $metadataSet['user_agent']);
    }

    public function testStoreSecurityMetadataStoresTimestamp(): void
    {
        // Arrange
        $contract = $this->createMock(PaymentContractInterface::class);
        $context = new EventContext([]);

        $beforeTime = time();

        $metadataSet = [];
        $contract->expects($this->atLeastOnce())
            ->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        // Act
        $service = $this->createService();
        $service->storeSecurityMetadata($contract, $context);

        $afterTime = time();

        // Assert
        $this->assertArrayHasKey('created_timestamp', $metadataSet);
        $this->assertGreaterThanOrEqual($beforeTime, $metadataSet['created_timestamp']);
        $this->assertLessThanOrEqual($afterTime, $metadataSet['created_timestamp']);
    }

    public function testStoreSecurityMetadataStoresSessionIdFromContext(): void
    {
        // Arrange
        $contract = $this->createMock(PaymentContractInterface::class);
        $context = new EventContext([
            'phpSessionId' => 'php_session_xyz789',
        ]);

        $metadataSet = [];
        $contract->expects($this->atLeastOnce())
            ->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        // Act
        $service = $this->createService();
        $service->storeSecurityMetadata($contract, $context);

        // Assert
        $this->assertArrayHasKey('session_id', $metadataSet);
        $this->assertEquals('php_session_xyz789', $metadataSet['session_id']);
    }

    public function testStoreSecurityMetadataStoresUserCountryFromContext(): void
    {
        // Arrange
        $contract = $this->createMock(PaymentContractInterface::class);
        $context = new EventContext([
            'userCountry' => 'DE',
        ]);

        $metadataSet = [];
        $contract->expects($this->atLeastOnce())
            ->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        // Act
        $service = $this->createService();
        $service->storeSecurityMetadata($contract, $context);

        // Assert
        $this->assertArrayHasKey('user_country', $metadataSet);
        $this->assertEquals('DE', $metadataSet['user_country']);
    }

    public function testStoreSecurityMetadataSkipsEmptyContextValues(): void
    {
        // Arrange
        $contract = $this->createMock(PaymentContractInterface::class);
        $context = new EventContext([
            'phpSessionId' => '', // Empty string
        ]);

        $metadataSet = [];
        $contract->expects($this->atLeastOnce())
            ->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        // Act
        $service = $this->createService();
        $service->storeSecurityMetadata($contract, $context);

        // Assert - session_id and user_country should NOT be set (only ip, agent, timestamp)
        $this->assertArrayNotHasKey('session_id', $metadataSet);
        $this->assertArrayNotHasKey('user_country', $metadataSet);
    }

    // --- getDeliveryAddressHash Tests ---

    public function testGetDeliveryAddressHashReturnsStoredValue(): void
    {
        // Arrange
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getMetadata')
            ->with('delivery_address_hash')
            ->willReturn('stored_hash_123');

        // Act
        $service = $this->createService();
        $result = $service->getDeliveryAddressHash($contract);

        // Assert
        $this->assertEquals('stored_hash_123', $result);
    }

    public function testGetDeliveryAddressHashReturnsNullWhenNotSet(): void
    {
        // Arrange
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getMetadata')
            ->with('delivery_address_hash')
            ->willReturn(null);

        // Act
        $service = $this->createService();
        $result = $service->getDeliveryAddressHash($contract);

        // Assert
        $this->assertNull($result);
    }

    // --- getDeliveryAddressId Tests ---

    public function testGetDeliveryAddressIdReturnsStoredValue(): void
    {
        // Arrange
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getMetadata')
            ->with('delivery_address_id')
            ->willReturn('deladdr_stored_789');

        // Act
        $service = $this->createService();
        $result = $service->getDeliveryAddressId($contract);

        // Assert
        $this->assertEquals('deladdr_stored_789', $result);
    }

    public function testGetDeliveryAddressIdReturnsNullWhenNotSet(): void
    {
        // Arrange
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getMetadata')
            ->with('delivery_address_id')
            ->willReturn(null);

        // Act
        $service = $this->createService();
        $result = $service->getDeliveryAddressId($contract);

        // Assert
        $this->assertNull($result);
    }
}
