<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\EventSystem\Handler;

use Psr\Log\LoggerInterface;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\OnePageCheckout\EventSystem\Event\OpcModalReopenedAfterExternalReturnEvent;
use OxidEsales\PaymentBase\EventSystem\Handler\HandlerInterface;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;

/**
 * OPC-96 — releases stranded Stripe reservations when the OPC modal is
 * re-opened after an external-payment round-trip.
 *
 * Background: Stripe creates an early NOT_FINISHED order (during
 * finalizeOrder → markVouchers) so a real order number exists before the
 * shopper leaves for Stripe's hosted Checkout. The associated voucher rows
 * are marked reserved. Two cleanup entry points already fire on the
 * standard OXID checkout return path — PaymentController::render() and
 * StripeOrderController::render() — but the OPC modal cancel URL is
 * rewritten by {@see OpcModalCancelUrlHandler} to skip those controllers
 * entirely and land on the origin page instead. Without this handler, the
 * voucher stays reserved against the stranded order and the next basket
 * recalculation drops it with an "invalid voucher" error.
 *
 * The handler mirrors the logic already used by
 * PaymentController::cleanupStaleCheckoutAttempt(): read the session's
 * `stripe_contract_id`, ask RetryCleanupService to release the contract
 * (which cancels the NOT_FINISHED order and un-marks the voucher via
 * OxidShopOrderService::resetVouchersForOrder), then clear the Stripe
 * session keys so subsequent checkout attempts start fresh.
 *
 * Behaviour is deliberately best-effort: a failure in RetryCleanupService
 * (e.g. DB unreachable) is logged and swallowed — the OPC modal must still
 * open. The user-visible symptom of a silent cleanup miss is exactly the
 * pre-fix bug, so we do not degrade UX further by throwing.
 *
 * @since opc-96 v1.0.0
 */
class OpcExternalReturnCleanupHandler implements HandlerInterface
{
    private const STRIPE_SESSION_KEYS = [
        'stripe_payment_intent_id',
        'stripe_client_secret',
        'stripe_checkout_session_id',
        'stripe_contract_id',
        'stripe_skip_addr_check',
    ];

    public function __construct(
        private readonly RetryCleanupService $cleanupService,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return OpcModalReopenedAfterExternalReturnEvent::class;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof OpcModalReopenedAfterExternalReturnEvent) {
            // Sprint 133 (F16): a wiring regression must not be silent.
            $this->logger?->warning('OpcExternalReturnCleanupHandler received an unexpected event type; skipping', [
                'expected' => OpcModalReopenedAfterExternalReturnEvent::class,
                'received' => $event::class,
            ]);

            return;
        }

        $contractId = $this->readContractIdFromSession();
        if ($contractId === null || $contractId === '') {
            return;
        }

        try {
            $this->cleanupService->cleanupPreviousAttempt($contractId);
        } catch (\Throwable $e) {
            $this->logCleanupFailure($e, $contractId);
            return;
        }

        $this->clearStripeSessionVariables();
    }

    /**
     * Testability seam: log a swallowed cleanup failure. Kept as a seam (like
     * the session seams below) so the handler's unit tests need no OXID
     * bootstrap / DI container to exercise the best-effort catch branch.
     */
    protected function logCleanupFailure(\Throwable $e, string $contractId): void
    {
        Registry::getLogger()->error('OPC-96: RetryCleanupService failed', [
            'error'      => $e->getMessage(),
            'contractId' => $contractId,
        ]);
    }

    /**
     * Testability seam: read `stripe_contract_id` from the OXID session.
     */
    protected function readContractIdFromSession(): ?string
    {
        $value = Registry::getSession()->getVariable('stripe_contract_id');
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Testability seam: clear all Stripe-owned session keys.
     *
     * Kept in sync with {@see \OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper::clearStripeSessionVariables()}.
     * The list is duplicated deliberately — the handler must not pull in
     * ControllerRequestHelper's constructor dependencies (config service,
     * language resolver, token service) just to delete five session keys.
     */
    protected function clearStripeSessionVariables(): void
    {
        $session = Registry::getSession();
        foreach (self::STRIPE_SESSION_KEYS as $key) {
            $session->deleteVariable($key);
        }
    }
}
