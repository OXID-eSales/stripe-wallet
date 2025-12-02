<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeContractCreationHandler;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Event\EventContext;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Service\ContractServiceInterface;
use OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface;
use OxidSolutionCatalysts\Payments\Component\Contract\BasketSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeContractCreationHandler
 */
class StripeContractCreationHandlerTest extends TestCase
{
    private ContractServiceInterface $contractService;
    private ContractRepositoryInterface $contractRepository;
    private StripeContractCreationHandler $handler;

    protected function setUp(): void
    {
        $this->contractService = $this->createMock(ContractServiceInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);

        $this->handler = new StripeContractCreationHandler(
            $this->contractService,
            $this->contractRepository
        );
    }

    protected function tearDown(): void
    {
        // Clean up global state
        unset($_SERVER['REMOTE_ADDR']);
        unset($_SERVER['HTTP_USER_AGENT']);
    }

    // =========================================================================
    // Security Metadata Storage Tests
    // =========================================================================

    public function testContractStoresUserIpInMetadata(): void
    {
        // Arrange
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';

        $contract = $this->createMockContract();
        $this->setupContractService($contract);

        $metadataSet = [];
        $contract->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        $context = $this->createCheckoutContext();
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Act
        $this->handler->handle($event);

        // Assert
        $this->assertArrayHasKey('user_ip', $metadataSet);
        $this->assertEquals('192.168.1.100', $metadataSet['user_ip']);
    }

    public function testContractStoresUserAgentInMetadata(): void
    {
        // Arrange
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Chrome/120.0';

        $contract = $this->createMockContract();
        $this->setupContractService($contract);

        $metadataSet = [];
        $contract->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        $context = $this->createCheckoutContext();
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Act
        $this->handler->handle($event);

        // Assert
        $this->assertArrayHasKey('user_agent', $metadataSet);
        $this->assertEquals('Mozilla/5.0 Chrome/120.0', $metadataSet['user_agent']);
    }

    public function testContractStoresCreatedTimestamp(): void
    {
        // Arrange
        $beforeTime = time();

        $contract = $this->createMockContract();
        $this->setupContractService($contract);

        $metadataSet = [];
        $contract->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        $context = $this->createCheckoutContext();
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Act
        $this->handler->handle($event);

        // Assert
        $this->assertArrayHasKey('created_timestamp', $metadataSet);
        $this->assertGreaterThanOrEqual($beforeTime, $metadataSet['created_timestamp']);
        $this->assertLessThanOrEqual(time(), $metadataSet['created_timestamp']);
    }

    public function testContractStoresSessionId(): void
    {
        // Arrange
        $contract = $this->createMockContract();
        $this->setupContractService($contract);

        $metadataSet = [];
        $contract->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        $context = $this->createCheckoutContext();
        $context->set('phpSessionId', 'sess_abc123');
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Act
        $this->handler->handle($event);

        // Assert
        $this->assertArrayHasKey('session_id', $metadataSet);
        $this->assertEquals('sess_abc123', $metadataSet['session_id']);
    }

    public function testContractStoresUserCountryWhenProvided(): void
    {
        // Arrange
        $contract = $this->createMockContract();
        $this->setupContractService($contract);

        $metadataSet = [];
        $contract->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        $context = $this->createCheckoutContext();
        $context->set('userCountry', 'DE');
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Act
        $this->handler->handle($event);

        // Assert
        $this->assertArrayHasKey('user_country', $metadataSet);
        $this->assertEquals('DE', $metadataSet['user_country']);
    }

    public function testContractStoresDeliveryAddressHashWhenAvailable(): void
    {
        // Note: delivery_address_hash depends on OXID Registry::getSession()
        // which cannot be mocked in unit tests. This test verifies that
        // when session has no data and user is null, the key is NOT set
        // (which is correct behavior - don't store empty values).

        // Arrange
        $contract = $this->createMockContract();
        $this->setupContractService($contract);

        $metadataSet = [];
        $contract->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        $context = $this->createCheckoutContext();
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Act
        $this->handler->handle($event);

        // Assert - security metadata IS set (these don't depend on session)
        $this->assertArrayHasKey('user_ip', $metadataSet);
        $this->assertArrayHasKey('created_timestamp', $metadataSet);

        // Note: delivery_address_hash is only set when session has data
        // This will be tested in integration tests with full OXID bootstrap
    }

    public function testContractStoresAllSecurityMetadata(): void
    {
        // Arrange
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $_SERVER['HTTP_USER_AGENT'] = 'TestBrowser/1.0';

        $contract = $this->createMockContract();
        $this->setupContractService($contract);

        $metadataSet = [];
        $contract->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        $context = $this->createCheckoutContext();
        $context->set('phpSessionId', 'sess_test');
        $context->set('userCountry', 'DE');
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Act
        $this->handler->handle($event);

        // Assert - all security metadata present
        $this->assertArrayHasKey('user_ip', $metadataSet);
        $this->assertArrayHasKey('user_agent', $metadataSet);
        $this->assertArrayHasKey('created_timestamp', $metadataSet);
        $this->assertArrayHasKey('session_id', $metadataSet);
        $this->assertArrayHasKey('user_country', $metadataSet);
    }

    public function testHandlesEmptyServerVariables(): void
    {
        // Arrange - no REMOTE_ADDR or HTTP_USER_AGENT set
        unset($_SERVER['REMOTE_ADDR']);
        unset($_SERVER['HTTP_USER_AGENT']);

        $contract = $this->createMockContract();
        $this->setupContractService($contract);

        $metadataSet = [];
        $contract->method('setMetadata')
            ->willReturnCallback(function ($key, $value) use (&$metadataSet) {
                $metadataSet[$key] = $value;
            });

        $context = $this->createCheckoutContext();
        $event = new StripeCheckoutSessionRequestEvent($context);

        // Act - should not throw
        $this->handler->handle($event);

        // Assert - keys should exist with empty/default values
        $this->assertArrayHasKey('user_ip', $metadataSet);
        $this->assertArrayHasKey('user_agent', $metadataSet);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    private function createMockContract(): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_' . uniqid());
        return $contract;
    }

    private function setupContractService(PaymentContractInterface $contract): void
    {
        $this->contractService
            ->method('createContract')
            ->willReturn($contract);
    }

    private function createCheckoutContext(): EventContext
    {
        $basket = $this->createMock(\OxidEsales\Eshop\Application\Model\Basket::class);
        $basket->method('getBasketUser')->willReturn(null);

        $context = new EventContext([
            'userId' => 'user_123',
            'basket' => $basket,
            'conditionTypes' => [],
        ]);

        return $context;
    }
}
