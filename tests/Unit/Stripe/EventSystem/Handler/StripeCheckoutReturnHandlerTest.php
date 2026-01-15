<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Tests\Unit\Stripe\EventSystem\Handler;

use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler\StripeCheckoutReturnHandler;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutReturnEvent;
use OxidSolutionCatalysts\Payments\Stripe\Service\Result\SecurityValidationResult;
use OxidSolutionCatalysts\Payments\Stripe\Service\CheckoutReturnServiceInterface;
use OxidSolutionCatalysts\Payments\Stripe\DTO\CheckoutReturnResult;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentAuthorizedEvent;
use OxidEsales\PaymentComponent\EventSystem\EventDispatcherInterface;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Contract\BasketSnapshot;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Service\ReturnSecurityValidatorInterface;
use OxidSolutionCatalysts\Payments\Stripe\Service\DeliveryAddressHashServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Sprint 22: Removed TestableStripeCheckoutReturnHandler - EventDispatcher is now
 * injected via constructor, no longer fetched lazily via ContainerFactory.
 */

/**
 * Unit tests for StripeCheckoutReturnHandler.
 *
 * Sprint 21: Tests updated for refactored handler with CheckoutReturnService injection.
 * Sprint 22: EventDispatcher now injected via constructor (no ContainerFactory).
 */
class StripeCheckoutReturnHandlerTest extends TestCase
{
    private CheckoutReturnServiceInterface&MockObject $checkoutReturnService;
    private ContractRepositoryInterface&MockObject $contractRepository;
    private ReturnSecurityValidatorInterface&MockObject $securityValidator;
    private DeliveryAddressHashServiceInterface&MockObject $deliveryAddressHashService;
    private LoggerInterface&MockObject $logger;
    private EventDispatcherInterface&MockObject $eventDispatcher;

    protected function setUp(): void
    {
        $this->checkoutReturnService = $this->createMock(CheckoutReturnServiceInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->securityValidator = $this->createMock(ReturnSecurityValidatorInterface::class);
        $this->deliveryAddressHashService = $this->createMock(DeliveryAddressHashServiceInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);

        // Default: security validation passes
        $this->securityValidator->method('validateReturn')->willReturn(
            new SecurityValidationResult(100, [], true)
        );

        // Default: delivery address hash service restores hash to $_REQUEST
        $this->deliveryAddressHashService->method('restoreHashForValidation')
            ->willReturnCallback(function (?string $hash): void {
                if ($hash !== null && $hash !== '') {
                    $_REQUEST['sDeliveryAddressMD5'] = $hash;
                }
            });

        // Clear any previous $_REQUEST state
        unset($_REQUEST['sDeliveryAddressMD5']);
    }

    protected function tearDown(): void
    {
        // Clean up $_REQUEST
        unset($_REQUEST['sDeliveryAddressMD5']);
    }

    private function createHandler(): StripeCheckoutReturnHandler
    {
        return new StripeCheckoutReturnHandler(
            $this->checkoutReturnService,
            $this->contractRepository,
            $this->securityValidator,
            $this->deliveryAddressHashService,
            $this->eventDispatcher,
            $this->logger
        );
    }

    public function testHandlerIgnoresNonStripeCheckoutReturnEvent(): void
    {
        $handler = $this->createHandler();

        $otherEvent = new class {
            public function getContext(): EventContext
            {
                return new EventContext([]);
            }
        };

        // Should not throw, just return early
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $handler->handle($otherEvent);
    }

    public function testReturnsCorrectHandledEventClass(): void
    {
        $this->assertEquals(
            StripeCheckoutReturnEvent::class,
            StripeCheckoutReturnHandler::getHandledEventClass()
        );
    }

    public function testSetsErrorWhenCheckoutSessionIdMissing(): void
    {
        $context = new EventContext([
            'contract_token' => 'token_123',
            'contract_id' => 'contract_123',
            // No checkoutSessionId
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertNotNull($context->get('error'));
        $this->assertStringContainsString('session ID', $context->get('error'));
        $this->assertEquals('payment', $context->get('redirectTarget'));
    }

    public function testSetsErrorWhenContractIdMissing(): void
    {
        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_123',
            'contract_token' => 'token_123',
            // No contract_id
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertNotNull($context->get('error'));
        $this->assertEquals('payment', $context->get('redirectTarget'));
    }

    public function testSetsErrorWhenContractTokenMissing(): void
    {
        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_123',
            'contract_id' => 'contract_123',
            // No contract_token
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertNotNull($context->get('error'));
        $this->assertEquals('payment', $context->get('redirectTarget'));
    }

    public function testDelegatesToCheckoutReturnService(): void
    {
        $this->checkoutReturnService
            ->expects($this->once())
            ->method('validateReturn')
            ->with('cs_test_123', 'contract_xyz', 'token_contract_xyz')
            ->willReturn(CheckoutReturnResult::success('contract_xyz', 'pi_test_123', 10000, 'eur'));

        $contract = $this->createContractMockWithMetadata('contract_xyz', [
            'delivery_address_hash' => 'hash_xyz',
        ]);
        $this->contractRepository
            ->method('findById')
            ->with('contract_xyz')
            ->willReturn($contract);

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_123',
            'contract_token' => 'token_contract_xyz',
            'contract_id' => 'contract_xyz',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testDispatchesPaymentAuthorizedEventOnSuccess(): void
    {
        $this->checkoutReturnService
            ->method('validateReturn')
            ->willReturn(CheckoutReturnResult::success('contract_paid', 'pi_test_paid', 10000, 'eur'));

        $contract = $this->createContractMockWithMetadata('contract_paid', [
            'delivery_address_hash' => 'hash_paid',
        ]);
        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        // When validation succeeds, should dispatch PaymentAuthorizedEvent
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PaymentAuthorizedEvent::class));

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_paid',
            'contract_token' => 'token_contract_paid',
            'contract_id' => 'contract_paid',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testSetsErrorWhenServiceValidationFails(): void
    {
        $this->checkoutReturnService
            ->method('validateReturn')
            ->willReturn(CheckoutReturnResult::failure('Payment not completed: unpaid'));

        // Should NOT dispatch event
        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_unpaid',
            'contract_token' => 'token_contract_unpaid',
            'contract_id' => 'contract_unpaid',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertNotNull($context->get('error'));
        $this->assertStringContainsString('not completed', $context->get('error'));
        $this->assertEquals('payment', $context->get('redirectTarget'));
    }

    public function testSetsContractInContext(): void
    {
        $this->checkoutReturnService
            ->method('validateReturn')
            ->willReturn(CheckoutReturnResult::success('contract_ctx', 'pi_test_ctx', 10000, 'eur'));

        $contract = $this->createContractMockWithMetadata('contract_ctx', [
            'delivery_address_hash' => 'hash_ctx',
        ]);
        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_ctx',
            'contract_token' => 'token_contract_ctx',
            'contract_id' => 'contract_ctx',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertSame($contract, $context->getContract());
    }

    public function testSetsPaymentIntentIdInContext(): void
    {
        $this->checkoutReturnService
            ->method('validateReturn')
            ->willReturn(CheckoutReturnResult::success('contract_pi', 'pi_test_xyz123', 10000, 'eur'));

        $contract = $this->createContractMockWithMetadata('contract_pi', [
            'delivery_address_hash' => 'hash_pi',
        ]);
        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_pi',
            'contract_token' => 'token_contract_pi',
            'contract_id' => 'contract_pi',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertEquals('pi_test_xyz123', $context->get('paymentIntentId'));
    }

    // =========================================================================
    // Security Validation Tests
    // =========================================================================

    public function testPerformsSecurityValidation(): void
    {
        $this->checkoutReturnService
            ->method('validateReturn')
            ->willReturn(CheckoutReturnResult::success('contract_sec', 'pi_test_sec', 10000, 'eur'));

        $contract = $this->createContractMockWithMetadata('contract_sec', [
            'user_ip' => '192.168.1.100',
            'delivery_address_hash' => 'hash_sec',
        ]);
        $this->contractRepository->method('findById')->willReturn($contract);

        // Security validator should be called
        $this->securityValidator
            ->expects($this->once())
            ->method('validateReturn')
            ->with($contract, $this->isType('array'))
            ->willReturn(new SecurityValidationResult(100, [], true));

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_sec',
            'contract_token' => 'token_contract_sec',
            'contract_id' => 'contract_sec',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testLogsWarningForLowSecurityScore(): void
    {
        $this->checkoutReturnService
            ->method('validateReturn')
            ->willReturn(CheckoutReturnResult::success('contract_warn', 'pi_test_warn', 10000, 'eur'));

        $contract = $this->createContractMockWithMetadata('contract_warn', [
            'delivery_address_hash' => 'hash_warn',
        ]);
        $this->contractRepository->method('findById')->willReturn($contract);

        // Low security score but still allowed
        $this->securityValidator = $this->createMock(ReturnSecurityValidatorInterface::class);
        $this->securityValidator->method('validateReturn')->willReturn(
            new SecurityValidationResult(60, ['ip_changed'], true)
        );

        // Should log warning
        $this->logger
            ->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Suspicious'),
                $this->callback(fn($ctx) => $ctx['score'] === 60)
            );

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_warn',
            'contract_token' => 'token_contract_warn',
            'contract_id' => 'contract_warn',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);
    }

    public function testBlocksReturnWhenSecurityCheckFails(): void
    {
        $this->checkoutReturnService
            ->method('validateReturn')
            ->willReturn(CheckoutReturnResult::success('contract_block', 'pi_test_block', 10000, 'eur'));

        $contract = $this->createContractMockWithMetadata('contract_block', [
            'delivery_address_hash' => 'hash_block',
        ]);
        $this->contractRepository->method('findById')->willReturn($contract);

        // Security check fails
        $this->securityValidator = $this->createMock(ReturnSecurityValidatorInterface::class);
        $this->securityValidator->method('validateReturn')->willReturn(
            new SecurityValidationResult(30, ['country_changed', 'very_late'], false)
        );

        // Should NOT dispatch event
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_block',
            'contract_token' => 'token_contract_block',
            'contract_id' => 'contract_block',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        // Should set error
        $this->assertNotNull($context->get('error'));
        $this->assertEquals('security_check_failed', $context->get('errorCode'));
    }

    // =========================================================================
    // Session Restoration Tests
    // =========================================================================

    public function testInjectsDeliveryAddressHashIntoRequest(): void
    {
        $expectedHash = 'abc123hash';

        $this->checkoutReturnService
            ->method('validateReturn')
            ->willReturn(CheckoutReturnResult::success('contract_inject', 'pi_test_inject', 10000, 'eur'));

        $contract = $this->createContractMockWithMetadata('contract_inject', [
            'delivery_address_hash' => $expectedHash,
        ]);
        $this->contractRepository->method('findById')->willReturn($contract);

        // Clear REQUEST to simulate return from Stripe
        unset($_REQUEST['sDeliveryAddressMD5']);

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_inject',
            'contract_token' => 'token_contract_inject',
            'contract_id' => 'contract_inject',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        // CRITICAL: Hash should be injected into $_REQUEST
        $this->assertEquals($expectedHash, $_REQUEST['sDeliveryAddressMD5']);
    }

    public function testRestoresDeliveryAddressIdToContext(): void
    {
        $this->checkoutReturnService
            ->method('validateReturn')
            ->willReturn(CheckoutReturnResult::success('contract_addr', 'pi_test_addr', 10000, 'eur'));

        $contract = $this->createContractMockWithMetadata('contract_addr', [
            'delivery_address_hash' => 'hash_addr',
            'delivery_address_id' => 'addr_456',
        ]);
        $this->contractRepository->method('findById')->willReturn($contract);

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_addr',
            'contract_token' => 'token_contract_addr',
            'contract_id' => 'contract_addr',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        // Address ID should be in context for session restoration
        $this->assertEquals('addr_456', $context->get('restoredDeliveryAddressId'));
    }

    // =========================================================================
    // Manual Capture (requires_capture) Tests
    // =========================================================================

    public function testHandleRequiresCaptureTransitionsContractToAuthorized(): void
    {
        // Result with requires_capture status
        $result = CheckoutReturnResult::success(
            'contract_rc',
            'pi_requires_capture',
            10000,
            'eur',
            'paid',
            'requires_capture'
        );

        $this->checkoutReturnService
            ->method('validateReturn')
            ->willReturn($result);

        $contract = $this->createContractMockForCapture('contract_rc', [
            'delivery_address_hash' => 'hash_rc',
        ]);

        // Contract should be saved after transition
        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $this->contractRepository->method('findById')->willReturn($contract);

        // Sprint 25: Should dispatch PaymentAuthorizedEvent for requires_capture
        // to trigger order creation (thankyou page needs order ID)
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(function ($event) {
                return $event instanceof \OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentAuthorizedEvent;
            }));

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_rc',
            'contract_token' => 'token_contract_rc',
            'contract_id' => 'contract_rc',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        // Should set requiresCapture flag
        $this->assertTrue($context->get('requiresCapture'));
        $this->assertEquals('authorized', $context->get('paymentStatus'));
    }

    public function testHandleRequiresCaptureStoresPaymentIntentId(): void
    {
        $result = CheckoutReturnResult::success(
            'contract_pi_store',
            'pi_to_store_123',
            10000,
            'eur',
            'paid',
            'requires_capture'
        );

        $this->checkoutReturnService
            ->method('validateReturn')
            ->willReturn($result);

        $contract = $this->createContractMockForCapture('contract_pi_store', [
            'delivery_address_hash' => 'hash_store',
        ]);

        // Should set metadata with payment intent ID
        $contract->expects($this->once())
            ->method('setMetadata')
            ->with('payment_intent_id', 'pi_to_store_123');

        $this->contractRepository->method('findById')->willReturn($contract);

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_store',
            'contract_token' => 'token_contract_store',
            'contract_id' => 'contract_pi_store',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        $this->assertEquals('pi_to_store_123', $context->get('paymentIntentId'));
    }

    public function testHandleSucceededDispatchesEventNormally(): void
    {
        // Result with succeeded status (auto-capture)
        $result = CheckoutReturnResult::success(
            'contract_auto',
            'pi_succeeded',
            10000,
            'eur',
            'paid',
            'succeeded'
        );

        $this->checkoutReturnService
            ->method('validateReturn')
            ->willReturn($result);

        $contract = $this->createContractMockWithMetadata('contract_auto', [
            'delivery_address_hash' => 'hash_auto',
        ]);
        $this->contractRepository->method('findById')->willReturn($contract);

        // Should dispatch PaymentAuthorizedEvent for succeeded status
        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(PaymentAuthorizedEvent::class));

        $context = new EventContext([
            'checkoutSessionId' => 'cs_test_auto',
            'contract_token' => 'token_contract_auto',
            'contract_id' => 'contract_auto',
        ]);
        $event = new StripeCheckoutReturnEvent($context);

        $handler = $this->createHandler();
        $handler->handle($event);

        // Should NOT set requiresCapture flag
        $this->assertNull($context->get('requiresCapture'));
    }

    // --- Helper methods ---

    private function createContractMockWithMetadata(string $contractId, array $metadata): PaymentContractInterface
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($contractId);

        $contract->method('getMetadata')->willReturnCallback(
            fn(string $key) => $metadata[$key] ?? null
        );
        $contract->method('getAllMetadata')->willReturn($metadata);

        $snapshot = BasketSnapshot::fromArray([
            'items' => [['title' => 'Test', 'unitPrice' => 10.00, 'quantity' => 1]],
            'discounts' => [],
            'totalGross' => 10.00,
            'totalNet' => 8.40,
            'totalVat' => 1.60,
            'currency' => 'EUR',
        ]);
        $contract->method('getBasketSnapshot')->willReturn($snapshot);

        return $contract;
    }

    /**
     * Create a contract mock that supports capture-related method expectations.
     *
     * @param string $contractId
     * @param array<string, mixed> $metadata
     * @return PaymentContractInterface&MockObject
     */
    private function createContractMockForCapture(string $contractId, array $metadata): PaymentContractInterface&MockObject
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn($contractId);
        $contract->method('getProviderOrderId')->willReturn(null);

        $contract->method('getMetadata')->willReturnCallback(
            fn(string $key) => $metadata[$key] ?? null
        );
        $contract->method('getAllMetadata')->willReturn($metadata);

        $snapshot = BasketSnapshot::fromArray([
            'items' => [['title' => 'Test', 'unitPrice' => 10.00, 'quantity' => 1]],
            'discounts' => [],
            'totalGross' => 10.00,
            'totalNet' => 8.40,
            'totalVat' => 1.60,
            'currency' => 'EUR',
        ]);
        $contract->method('getBasketSnapshot')->willReturn($snapshot);

        return $contract;
    }
}
