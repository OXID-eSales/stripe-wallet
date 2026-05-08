<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Service;

use DateTimeImmutable;
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

    private function stripeCustomerExists(string $customerId): bool
    {
        try {
            $adapter = $this->adapterFactory->getStripeAdapter();
            $adapter->retrieveStripeCustomer($customerId);
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }
}
