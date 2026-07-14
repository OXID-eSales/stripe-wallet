<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\EventSystem\Handler;

use OxidEsales\OnePageCheckout\EventSystem\Event\OpcModalReopenedAfterExternalReturnEvent;
use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\Payments\Stripe\EventSystem\Handler\OpcExternalReturnCleanupHandler;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use PHPUnit\Framework\TestCase;

/**
 * OPC-96 — Stripe-side subscriber to OpcModalReopenedAfterExternalReturnEvent.
 *
 * The OPC module dispatches the event when its modal is re-opened after an
 * external-payment round-trip. This handler releases the reservations that
 * Stripe made during early-order creation — otherwise the applied voucher
 * remains marked as used against a stranded NOT_FINISHED order and the next
 * basket recalculation trips a VoucherException.
 *
 * Behaviour under test:
 *   AC-05: when the session holds a `stripe_contract_id`, invoke
 *          RetryCleanupService::cleanupPreviousAttempt() with that id AND
 *          then clear the Stripe-owned session keys.
 *   AC-06: when the session has no `stripe_contract_id`, do NOT touch the
 *          cleanup service, do NOT throw. The handler must be safe to run
 *          on every reopen event.
 *   (extra) Handler subscribes to the OPC event class name — pin the
 *          contract so a rename on either side is caught by unit tests.
 *   (extra) Any exception raised by the cleanup service is swallowed —
 *          this is a best-effort cleanup, never a request-breaking path.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(OpcExternalReturnCleanupHandler::class)]
class OpcExternalReturnCleanupHandlerTest extends TestCase
{
    public function testHandlerImplementsHandlerInterface(): void
    {
        $handler = $this->makeHandler(cleanupService: $this->makeCleanupSpy());
        $this->assertInstanceOf(HandlerInterface::class, $handler);
    }

    public function testHandledEventClassIsTheOpcReopenEvent(): void
    {
        $this->assertSame(
            OpcModalReopenedAfterExternalReturnEvent::class,
            OpcExternalReturnCleanupHandler::getHandledEventClass(),
        );
    }

    /**
     * AC-05: session has stripe_contract_id → cleanup fires with that id AND
     * Stripe session vars are cleared.
     */
    public function testCallsCleanupAndClearsSessionWhenContractIdPresent(): void
    {
        $spy = $this->makeCleanupSpy();
        $handler = $this->makeHandler(
            cleanupService: $spy,
            contractId: 'contract_abc123',
        );

        $handler->handle(new OpcModalReopenedAfterExternalReturnEvent(
            modalId: 'opc_x_1',
            originUrl: 'https://shop.test/',
        ));

        $this->assertSame(['contract_abc123'], $spy->receivedContractIds);
        $this->assertSame(1, $handler->clearedSessionCallCount());
    }

    /**
     * AC-06: session has no contract id → skip cleanup + no session
     * mutation. Reopens from shoppers who did NOT initiate a Stripe
     * checkout attempt must be no-ops.
     */
    public function testNoOpWhenNoContractIdInSession(): void
    {
        $spy = $this->makeCleanupSpy();
        $handler = $this->makeHandler(
            cleanupService: $spy,
            contractId: null,
        );

        $handler->handle(new OpcModalReopenedAfterExternalReturnEvent(
            modalId: 'opc_x_1',
            originUrl: 'https://shop.test/',
        ));

        $this->assertSame([], $spy->receivedContractIds);
        $this->assertSame(0, $handler->clearedSessionCallCount());
    }

    /**
     * Guard against handler ever being invoked with a wrong event type by a
     * mis-registered dispatcher — no cleanup, no exception, no mutation.
     */
    public function testHandlerIgnoresEventOfWrongType(): void
    {
        $spy = $this->makeCleanupSpy();
        $handler = $this->makeHandler(
            cleanupService: $spy,
            contractId: 'contract_abc123',
        );

        $handler->handle(new \stdClass());

        $this->assertSame([], $spy->receivedContractIds);
        $this->assertSame(0, $handler->clearedSessionCallCount());
    }

    /**
     * The seam is additive — a broken cleanup service must never propagate
     * out of the handler and break the modal-reopen request that triggered
     * the event.
     */
    public function testCleanupExceptionIsSwallowed(): void
    {
        $throwing = new class extends RetryCleanupService {
            public function __construct()
            {
            }
            public function cleanupPreviousAttempt(?string $contractId): bool
            {
                throw new \RuntimeException('DB unreachable');
            }
        };

        $handler = $this->makeHandler(
            cleanupService: $throwing,
            contractId: 'contract_abc123',
        );

        // The lack of an exception IS the assertion; this call must return.
        $handler->handle(new OpcModalReopenedAfterExternalReturnEvent(
            modalId: 'opc_x_1',
            originUrl: 'https://shop.test/',
        ));

        $this->assertSame(0, $handler->clearedSessionCallCount());
    }

    /**
     * Build a testable handler subclass that overrides the two protected
     * seams (session read + clear) so no OXID bootstrap is required.
     */
    private function makeHandler(
        RetryCleanupService $cleanupService,
        ?string $contractId = null,
    ): object {
        return new class ($cleanupService, $contractId) extends OpcExternalReturnCleanupHandler {
            private int $cleared = 0;
            public function __construct(
                RetryCleanupService $cleanupService,
                private readonly ?string $contractIdFixture,
            ) {
                parent::__construct($cleanupService);
            }
            protected function readContractIdFromSession(): ?string
            {
                return $this->contractIdFixture;
            }
            protected function clearStripeSessionVariables(): void
            {
                $this->cleared++;
            }
            public function clearedSessionCallCount(): int
            {
                return $this->cleared;
            }
        };
    }

    /**
     * @return RetryCleanupService&object{receivedContractIds: array<int, ?string>}
     */
    private function makeCleanupSpy(): RetryCleanupService
    {
        return new class extends RetryCleanupService {
            /** @var array<int, ?string> */
            public array $receivedContractIds = [];
            public function __construct()
            {
            }
            public function cleanupPreviousAttempt(?string $contractId): bool
            {
                $this->receivedContractIds[] = $contractId;
                return true;
            }
        };
    }
}
