<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Adapter\Exception\PaymentAdapterException;
use OxidEsales\PaymentBase\Contract\PaymentCustomer;
use OxidEsales\PaymentBase\Repository\PaymentCustomerRepositoryInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Resolves or creates Stripe Customer objects for OXID users.
 *
 * Sprint 45: Stripe Customer lifecycle.
 *
 * @since 2.0.0
 */
class StripeCustomerService implements StripeCustomerServiceInterface
{
    /** Stripe's error code for "this object does not exist". */
    private const STRIPE_ERROR_RESOURCE_MISSING = 'resource_missing';

    private LoggerInterface $logger;

    public function __construct(
        private readonly PaymentCustomerRepositoryInterface $customerRepository,
        private readonly StripeAdapterFactoryInterface $adapterFactory,
        private readonly CustomerDataSanitizer $sanitizer,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function resolveStripeCustomerId(string $userId, string $email, string $name): string
    {
        $existing = $this->customerRepository->findByUserId($userId);
        if ($existing !== null && $existing->getPaymentCustomerId() !== null) {
            if ($this->stripeCustomerExists($existing->getPaymentCustomerId())) {
                $this->logger->debug('Reusing existing Stripe Customer', [
                    'userId' => $userId,
                    'customerId' => $existing->getPaymentCustomerId(),
                ]);
                return $existing->getPaymentCustomerId();
            }

            $this->logger->warning('Stale Stripe Customer ID, creating new one', [
                'userId' => $userId,
                'staleCustomerId' => $existing->getPaymentCustomerId(),
            ]);
        }

        $adapter = $this->adapterFactory->getStripeAdapter();
        $stripeCustomer = $adapter->createStripeCustomer([
            'email' => $this->sanitizer->sanitize($email, 320),
            'name' => $this->sanitizer->sanitize($name),
            'metadata' => ['oxid_user_id' => $userId],
        ]);

        $now = new DateTimeImmutable();

        if ($existing !== null) {
            $existing->setPaymentCustomerId($stripeCustomer->id);
            $existing->setUpdatedAt($now);
            $this->customerRepository->save($existing);
        } else {
            $record = new PaymentCustomer(
                bin2hex(random_bytes(16)),
                $userId,
                $now,
                $now
            );
            $record->setPaymentCustomerId($stripeCustomer->id);
            $this->customerRepository->save($record);
        }

        $this->logger->info('Created Stripe Customer', [
            'userId' => $userId,
            'customerId' => $stripeCustomer->id,
        ]);

        return $stripeCustomer->id;
    }

    /**
     * Sprint 133 · Story 10 (F10): only Stripe's `resource_missing` proves the
     * customer is gone. This used to catch \Throwable and answer false, so one
     * transient error — a timeout, a 429, a 500 — was read as "stale", a second
     * Stripe Customer was created, and the stored mapping was overwritten. Saved
     * payment methods, mandates and Radar history on the old Customer became
     * unreachable for that user, and the shop could not undo it.
     *
     * Anything that is not a definitive 404 is rethrown, so checkout fails
     * loudly and retryably instead of mutating durable state on a guess.
     */
    private function stripeCustomerExists(string $customerId): bool
    {
        try {
            $this->adapterFactory->getStripeAdapter()->retrieveStripeCustomer($customerId);

            return true;
        } catch (PaymentAdapterException $e) {
            if ($e->getErrorCode() === self::STRIPE_ERROR_RESOURCE_MISSING) {
                return false;
            }

            $this->logger->error('Could not verify the Stripe Customer; refusing to assume it is gone', [
                'customerId' => $customerId,
                'errorCode' => $e->getErrorCode(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
