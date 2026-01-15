<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Stripe\Controller\GraphQL;

use OxidEsales\PaymentComponent\EventSystem\EventDispatcher;
use OxidEsales\PaymentComponent\EventSystem\Event\Payment\PaymentInitiatedEvent;
use OxidSolutionCatalysts\Payments\Stripe\Service\EncryptionService;
use OxidSolutionCatalysts\Payments\Stripe\Service\ErrorResponseFactory;
use Psr\Log\LoggerInterface;

/**
 * OnePageController - GraphQL resolver for one-page checkout flow
 *
 * Handles:
 * - Address updates (updateAddress mutation)
 * - Payment processing (processPayment mutation)
 *
 * Based on the event-driven architecture pattern from the sequence diagram
 */
class OnePageController
{
    public function __construct(
        private readonly EventDispatcher $eventDispatcher,
        private readonly EncryptionService $encryptionService,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Handle updateAddress GraphQL mutation
     *
     * @param array $input GraphQL input from UpdateAddressInput
     * @return array Response matching AddressUpdateResponse type
     */
    public function updateAddress(array $input): array
    {
        try {
            // Validate input
            $errors = $this->validateAddressInput($input);
            if (!empty($errors)) {
                return ErrorResponseFactory::validationError($errors, 'Please correct the errors and try again');
            }

            // Extract addresses
            $billingAddress = $input['billingAddress'];
            $shippingAddress = $input['useBillingAsShipping'] ?? true
                ? $billingAddress
                : ($input['shippingAddress'] ?? null);

            // Get customer ID from session/context
            $customerId = $this->getCurrentCustomerId();

            // Emit AddressUpdatedEvent for subscribers to react
            $event = new AddressUpdatedEvent(
                customerId: $customerId,
                billingAddress: $billingAddress,
                shippingAddress: $shippingAddress
            );

            $this->eventDispatcher->dispatch($event);

            $this->logger->info('Address updated successfully', [
                'customerId' => $customerId,
            ]);

            return [
                'success' => true,
                'message' => 'Address updated successfully',
                'errors' => [],
                'code' => null,
                'retryable' => false,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Address update failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return ErrorResponseFactory::systemError(
                'Failed to update address. Please try again.',
                $e
            );
        }
    }

    /**
     * Handle processPayment GraphQL mutation
     *
     * @param array $input GraphQL input from ProcessPaymentInput
     * @return array Response matching PaymentResponse type
     */
    public function processPayment(array $input): array
    {
        try {
            // Validate input
            $errors = $this->validatePaymentInput($input);
            if (!empty($errors)) {
                $response = ErrorResponseFactory::validationError($errors, 'Please correct the payment information');
                $response['orderId'] = null;
                $response['status'] = 'FAILED';
                $response['redirectUrl'] = null;
                $response['clientSecret'] = null;
                return $response;
            }

            // Decrypt encrypted card data
            $decryptedData = $this->encryptionService->decrypt($input['encryptedData']);

            if ($decryptedData === null) {
                throw new \RuntimeException('Failed to decrypt payment data');
            }

            // Get customer ID and create contract ID
            $customerId = $this->getCurrentCustomerId();
            $contractId = $this->generateContractId();

            // Emit PaymentInitiatedEvent
            // The PaymentHandler will subscribe to this event and handle actual payment processing
            $event = new PaymentInitiatedEvent(
                contractId: $contractId,
                paymentData: $decryptedData,
                customerId: $customerId,
                amount: $input['amount'] / 100, // Convert cents to decimal
                currency: $input['currency'],
                returnUrl: $input['returnUrl'] ?? null,
                saveCard: $input['saveCard'] ?? false
            );

            $this->eventDispatcher->dispatch($event);

            $this->logger->info('Payment initiated', [
                'contractId' => $contractId,
                'customerId' => $customerId,
                'amount' => $input['amount'],
                'currency' => $input['currency'],
            ]);

            // Return pending response
            // The actual payment result will be updated via events
            return [
                'success' => true,
                'orderId' => null, // Will be set by PaymentCompletedEvent
                'status' => 'PENDING',
                'message' => 'Payment is being processed',
                'redirectUrl' => null,
                'clientSecret' => null,
                'errors' => [],
                'code' => null,
                'retryable' => false,
            ];
        } catch (\RuntimeException $e) {
            $this->logger->error('Payment processing failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $response = ErrorResponseFactory::systemError(
                'Unable to process payment. Please check your payment details and try again.',
                $e
            );
            $response['orderId'] = null;
            $response['status'] = 'FAILED';
            $response['redirectUrl'] = null;
            $response['clientSecret'] = null;
            return $response;
        } catch (\Exception $e) {
            $this->logger->error('Unexpected error during payment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $response = ErrorResponseFactory::systemError(
                'An unexpected error occurred. Please try again.',
                $e
            );
            $response['orderId'] = null;
            $response['status'] = 'FAILED';
            $response['redirectUrl'] = null;
            $response['clientSecret'] = null;
            return $response;
        }
    }

    /**
     * Handle abandonCheckout GraphQL mutation
     *
     * @param array $input GraphQL input from AbandonCheckoutInput
     * @return array Response matching AbandonCheckoutResponse type
     */
    public function abandonCheckout(array $input): array
    {
        try {
            // Extract data from input
            $sessionId = $input['sessionId'];
            $reason = $input['reason'];
            $checkoutState = $input['checkoutState'];
            $contractId = $input['contractId'] ?? null;
            $metadata = $input['metadata'] ?? null;

            // Get customer ID
            $customerId = $this->getCurrentCustomerId();

            // Calculate cart total and currency from checkout state
            $cartTotal = $checkoutState['cartTotal'] ?? null;
            $currency = $checkoutState['currency'] ?? null;

            // If not provided, calculate from cart items
            if ($cartTotal === null && !empty($checkoutState['cartItems'])) {
                $cartTotal = 0;
                foreach ($checkoutState['cartItems'] as $item) {
                    $cartTotal += $item['price'] * $item['quantity'];
                }
            }

            // Emit CheckoutAbandonedEvent
            $event = new CheckoutAbandonedEvent(
                sessionId: $sessionId,
                customerId: $customerId,
                reason: strtolower($reason),
                checkoutState: $checkoutState,
                contractId: $contractId,
                cartTotal: $cartTotal,
                currency: $currency,
                metadata: $metadata
            );

            $this->eventDispatcher->dispatch($event);

            $this->logger->info('Checkout abandonment tracked', [
                'sessionId' => $sessionId,
                'customerId' => $customerId,
                'reason' => $reason,
                'stage' => $checkoutState['currentStage'] ?? 'unknown',
            ]);

            return [
                'success' => true,
                'message' => 'Checkout abandonment tracked successfully',
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to track checkout abandonment', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed to track abandonment: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Validate address input
     */
    private function validateAddressInput(array $input): array
    {
        $errors = [];

        if (!isset($input['billingAddress'])) {
            $errors[] = [
                'field' => 'billingAddress',
                'message' => 'Billing address is required',
            ];
        }

        $required = ['firstName', 'lastName', 'street', 'city', 'zip', 'countryCode', 'email'];
        foreach ($required as $field) {
            if (empty($input['billingAddress'][$field] ?? null)) {
                $errors[] = [
                    'field' => "billingAddress.$field",
                    'message' => "$field is required",
                ];
            }
        }

        // Validate email format
        if (isset($input['billingAddress']['email'])) {
            $email = $input['billingAddress']['email'];
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = [
                    'field' => 'billingAddress.email',
                    'message' => 'Invalid email format',
                ];
            }
        }

        return $errors;
    }

    /**
     * Validate payment input
     */
    private function validatePaymentInput(array $input): array
    {
        $errors = [];

        if (empty($input['encryptedData'])) {
            $errors[] = [
                'field' => 'encryptedData',
                'message' => 'Encrypted data is required',
            ];
        }

        if (!isset($input['amount']) || $input['amount'] <= 0) {
            $errors[] = [
                'field' => 'amount',
                'message' => 'Amount must be greater than 0',
            ];
        }

        if (empty($input['currency'])) {
            $errors[] = [
                'field' => 'currency',
                'message' => 'Currency is required',
            ];
        }

        return $errors;
    }

    /**
     * Get current customer ID from session/context
     * TODO: Implement based on OXID session management
     */
    private function getCurrentCustomerId(): string
    {
        // Placeholder - implement based on OXID's user session
        return 'customer_' . uniqid();
    }

    /**
     * Generate unique contract ID
     */
    private function generateContractId(): string
    {
        return 'contract_' . uniqid() . '_' . time();
    }
}
