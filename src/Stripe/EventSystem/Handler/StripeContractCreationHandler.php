<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\EventSystem\Handler;

use InvalidArgumentException;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Registry;
use OxidSolutionCatalysts\Payments\Component\EventSystem\Handler\HandlerInterface;
use OxidSolutionCatalysts\Payments\Component\Repository\ContractRepositoryInterface;
use OxidSolutionCatalysts\Payments\Component\Service\ContractServiceInterface;
use OxidSolutionCatalysts\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;

/**
 * Creates payment contract for Stripe Checkout Session flow.
 *
 * This handler runs BEFORE StripeCheckoutSessionHandler (via priority)
 * to ensure the contract exists when the session is created.
 *
 * Priority: 100 (higher than StripeCheckoutSessionHandler default 0)
 */
class StripeContractCreationHandler implements HandlerInterface
{
    public function __construct(
        private ContractServiceInterface $contractService,
        private ContractRepositoryInterface $contractRepository
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return StripeCheckoutSessionRequestEvent::class;
    }

    public function getPriority(): int
    {
        return 100;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof StripeCheckoutSessionRequestEvent) {
            return;
        }

        $context = $event->getContext();

        // Skip if contract already exists
        if ($context->getContract() !== null) {
            return;
        }

        $userId = $context->get('userId');
        if (!is_string($userId) || $userId === '') {
            throw new InvalidArgumentException('User ID is required');
        }

        $basket = $context->get('basket');
        if (!is_object($basket)) {
            throw new InvalidArgumentException('Basket is required');
        }

        $conditionTypes = $context->get('conditionTypes', []);
        if (!is_array($conditionTypes)) {
            $conditionTypes = [];
        }

        /** @var array<int, string> $validatedConditionTypes */
        $validatedConditionTypes = array_values(array_filter($conditionTypes, 'is_string'));

        $contract = $this->contractService->createContract(
            $userId,
            $basket,
            $validatedConditionTypes
        );

        // Store delivery address hash in contract metadata
        // This is critical for order finalization after returning from Stripe
        $this->storeDeliveryAddressHash($contract, $basket);

        // Save contract again to persist the metadata
        // (createContract already saved it, but metadata was added after)
        $this->contractRepository->save($contract);

        $context->setContract($contract);
        $context->set('contractId', $contract->getId());
    }

    /**
     * Store delivery address hash in contract metadata for later restoration.
     *
     * OXID validates that the delivery address hasn't changed between
     * payment initiation and order finalization. When returning from Stripe,
     * we need to restore the original hash to pass this validation.
     */
    private function storeDeliveryAddressHash(
        \OxidSolutionCatalysts\Payments\Component\Contract\PaymentContractInterface $contract,
        object $basket
    ): void {
        $session = Registry::getSession();

        // First try to get the hash from session (most accurate)
        $addressHash = $session->getVariable('sDelAddrMD5');
        $deliveryAddressId = $session->getVariable('deladrid');

        // If no hash in session, compute from user
        if (empty($addressHash)) {
            /** @var User|null $user */
            $user = $basket->getBasketUser();
            if ($user !== null) {
                $addressHash = $user->getEncodedDeliveryAddress();
            }
        }

        // Store the hash in contract metadata
        if (!empty($addressHash)) {
            $contract->setMetadata('delivery_address_hash', $addressHash);
        }

        // Also store delivery address ID if present
        if (!empty($deliveryAddressId)) {
            $contract->setMetadata('delivery_address_id', $deliveryAddressId);
        }
    }
}
