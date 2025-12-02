<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Service;

use RuntimeException;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\DatabaseProvider;
use OxidEsales\Eshop\Core\UtilsObject;
use OxidSolutionCatalysts\Payments\Component\Repository\PaymentCustomerRepositoryInterface;

/**
 * Stripe customer management service
 *
 * This service manages the lifecycle of Stripe Customer objects and their mapping
 * to OXID users. It ensures each OXID user has a corresponding Stripe Customer
 * for payment processing.
 *
 * Responsibilities:
 * - Creates Stripe Customer records via Stripe API
 * - Links OXID users to Stripe customers
 * - Stores customer ID mapping in database (osc_stripe_customer_mapping)
 * - Updates customer data when user details change
 * - Handles customer ID retrieval and verification
 * - Manages customer lifecycle and data synchronization
 *
 * Why it's needed:
 * - Required for saved payment methods (vaulting/tokenization)
 * - Enables customer-specific payment analytics in Stripe dashboard
 * - Allows merchants to see customer payment history
 * - Necessary for subscription payments and recurring billing
 * - Improves fraud detection by maintaining customer identity
 * - Enables Stripe's customer portal features
 *
 * Database:
 * Uses osc_stripe_customer_mapping table to store OXID user ID to Stripe customer ID mapping
 *
 * Initialization:
 * This service supports lazy initialization and can be constructed without API keys.
 * It will initialize automatically when first used if configuration is available.
 *
 * @package OxidSolutionCatalysts\Payments\Stripe\Service
 * @author OXID eSales AG
 * @since 1.0.0
 */
class StripeCustomerService implements InitializableServiceInterface
{
    use InitializableServiceTrait;

    private ?StripeClient $stripe = null;
    private ModuleConfigurationService $config;
    private ?PaymentCustomerRepositoryInterface $customerRepository;

    public function __construct(
        ModuleConfigurationService $config,
        ?PaymentCustomerRepositoryInterface $customerRepository = null
    ) {
        $this->config = $config;
        $this->customerRepository = $customerRepository;
    }

    /**
     * @inheritDoc
     */
    public function canInitialize(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * @inheritDoc
     */
    protected function doInitialize(): void
    {
        $secretKey = $this->config->getToken();

        if (empty($secretKey)) {
            throw new RuntimeException('Stripe secret key is not configured');
        }

        $this->stripe = new StripeClient($secretKey);
    }

    /**
     * Get or create Stripe customer for OXID user
     *
     * @param User $user
     * @return string Stripe customer ID
     * @throws \RuntimeException
     */
    public function getOrCreateStripeCustomer(User $user): string
    {
        $this->ensureInitialized();

        // Check if customer already exists
        $stripeCustomerId = $this->getStoredStripeCustomerId($user->getId());

        if ($stripeCustomerId) {
            // Verify customer still exists in Stripe
            try {
                $this->stripe->customers->retrieve($stripeCustomerId);
                return $stripeCustomerId;
            } catch (ApiErrorException $e) {
                // Customer deleted in Stripe, create new one
                Registry::getLogger()->warning('Stripe customer not found, creating new', [
                    'user_id' => $user->getId(),
                    'old_customer_id' => $stripeCustomerId,
                ]);
            }
        }

        // Create new Stripe customer
        return $this->createStripeCustomer($user);
    }

    /**
     * Create new Stripe customer
     *
     * @param User $user
     * @return string Stripe customer ID
     * @throws \RuntimeException
     */
    private function createStripeCustomer(User $user): string
    {
        $this->ensureInitialized();

        try {
            $customer = $this->stripe->customers->create([
                'email' => $user->getFieldData('oxusername'),
                'name' => $user->getFieldData('oxfname') . ' ' . $user->getFieldData('oxlname'),
                'phone' => $user->getFieldData('oxfon'),
                'metadata' => [
                    'oxid_user_id' => $user->getId(),
                    'oxid_customer_number' => $user->getFieldData('oxcustnr'),
                ],
            ]);

            // Store customer ID
            $this->storeStripeCustomerId($user->getId(), $customer->id);

            Registry::getLogger()->info('Stripe customer created', [
                'user_id' => $user->getId(),
                'customer_id' => $customer->id,
            ]);

            return $customer->id;
        } catch (ApiErrorException $e) {
            Registry::getLogger()->error('Failed to create Stripe customer', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException(
                'Failed to create customer: ' . $e->getMessage(),
                $e->getCode(),
                $e
            );
        }
    }

    /**
     * Get stored Stripe customer ID
     *
     * @param string $userId
     * @return string|null
     */
    private function getStoredStripeCustomerId(string $userId): ?string
    {
        // Use repository if available (preferred - LSP compliant)
        if ($this->customerRepository !== null) {
            return $this->customerRepository->findPaymentCustomerId($userId);
        }

        // Fallback to raw SQL (legacy - for backward compatibility)
        $db = DatabaseProvider::getDb();

        $customerId = $db->getOne(
            "SELECT OXSTRIPECUSTOMERID FROM osc_stripe_customer_mapping WHERE OXUSERID = ?",
            [$userId]
        );

        return $customerId ?: null;
    }

    /**
     * Store Stripe customer ID
     *
     * @param string $userId
     * @param string $stripeCustomerId
     */
    private function storeStripeCustomerId(string $userId, string $stripeCustomerId): void
    {
        // Use repository if available (preferred - LSP compliant)
        if ($this->customerRepository !== null) {
            $this->customerRepository->savePaymentCustomerId($userId, $stripeCustomerId);
            return;
        }

        // Fallback to raw SQL (legacy - for backward compatibility)
        $db = DatabaseProvider::getDb();

        $sql = "INSERT INTO osc_stripe_customer_mapping
                (OXID, OXSHOPID, OXUSERID, OXSTRIPECUSTOMERID, OXCREATED)
                VALUES (?, ?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE
                OXSTRIPECUSTOMERID = VALUES(OXSTRIPECUSTOMERID),
                OXUPDATED = NOW()";

        $db->execute($sql, [
            UtilsObject::getInstance()->generateUId(),
            Registry::getConfig()->getShopId(),
            $userId,
            $stripeCustomerId,
        ]);
    }

    /**
     * Update customer data in Stripe
     *
     * @param User $user
     * @return bool
     */
    public function updateCustomerData(User $user): bool
    {
        $this->ensureInitialized();

        $stripeCustomerId = $this->getStoredStripeCustomerId($user->getId());

        if (!$stripeCustomerId) {
            return false;
        }

        try {
            $this->stripe->customers->update($stripeCustomerId, [
                'email' => $user->getFieldData('oxusername'),
                'name' => $user->getFieldData('oxfname') . ' ' . $user->getFieldData('oxlname'),
                'phone' => $user->getFieldData('oxfon'),
            ]);

            return true;
        } catch (ApiErrorException $e) {
            Registry::getLogger()->error('Failed to update Stripe customer', [
                'user_id' => $user->getId(),
                'customer_id' => $stripeCustomerId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}
