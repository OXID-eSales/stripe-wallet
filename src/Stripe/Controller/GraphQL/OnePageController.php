<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Controller\GraphQL;

use OxidEsales\PaymentComponent\EventSystem\EventDispatcher;
use OxidEsales\Payments\Stripe\Service\EncryptionService;
use OxidEsales\Payments\Stripe\Service\ErrorResponseFactory;
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
     * @param array<string, mixed> $input GraphQL input from UpdateAddressInput
     * @return array<string, mixed> Response matching AddressUpdateResponse type
     */
    public function updateAddress(array $input): array
    {
        try {
            // Validate input
            $errors = $this->validateAddressInput($input);
            if (!empty($errors)) {
                /** @var array<int, array{field: string, message: string}> $errors */
                return ErrorResponseFactory::validationError($errors, 'Please correct the errors and try again');
            }

            // Extract addresses
            /** @var array<string, mixed> $billingAddress */
            $billingAddress = $input['billingAddress'] ?? [];
            /** @var array<string, mixed>|null $shippingAddress */
            $shippingAddress = ($input['useBillingAsShipping'] ?? true)
                ? $billingAddress
                : ($input['shippingAddress'] ?? null);

            // Get customer ID from session/context
            $customerId = $this->getCurrentCustomerId();

            // Emit AddressUpdatedEvent for subscribers to react
            // @phpstan-ignore class.notFound
            $event = new AddressUpdatedEvent(
                customerId: $customerId,
                billingAddress: $billingAddress,
                shippingAddress: $shippingAddress
            );

            $this->eventDispatcher->dispatch($event); // @phpstan-ignore argument.type

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
     * @param array<string, mixed> $input GraphQL input from ProcessPaymentInput
     * @return array<string, mixed> Response matching PaymentResponse type
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
            // TODO: Implement proper PaymentInitiatedEvent creation with correct constructor parameters
            // The PaymentInitiatedEvent from payment-component requires:
            // context (EventContextInterface), paymentMethodId (string), amount (float),
            // currency (string), returnUrl (string), cancelUrl (string)
            //
            // Current placeholder stores payment data for later processing
            /** @var int $inputAmount */
            $inputAmount = $input['amount'] ?? 0;
            /** @var string $inputCurrency */
            $inputCurrency = $input['currency'] ?? 'EUR';
            /** @var string $inputReturnUrl */
            $inputReturnUrl = $input['returnUrl'] ?? '';

            // Store payment intent data in session for now
            // TODO: Replace with proper event dispatching once EventContext is available
            $paymentData = [
                'contract_id' => $contractId,
                'customer_id' => $customerId,
                'amount' => $inputAmount / 100,
                'currency' => $inputCurrency,
                'return_url' => $inputReturnUrl,
                'save_card' => $input['saveCard'] ?? false,
                'decrypted_data' => $decryptedData,
            ];

            // Log that we need to implement proper event dispatching
            $this->logger->debug('PaymentInitiatedEvent not yet implemented - storing data', $paymentData);

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
     * @param array<string, mixed> $input GraphQL input from AbandonCheckoutInput
     * @return array<string, mixed> Response matching AbandonCheckoutResponse type
     */
    public function abandonCheckout(array $input): array
    {
        try {
            // Extract data from input
            $sessionId = is_string($input['sessionId'] ?? null) ? $input['sessionId'] : '';
            $reason = is_string($input['reason'] ?? null) ? $input['reason'] : '';
            /** @var array<string, mixed> $checkoutState */
            $checkoutState = is_array($input['checkoutState'] ?? null) ? $input['checkoutState'] : [];
            $contractId = isset($input['contractId']) && is_string($input['contractId']) ? $input['contractId'] : null;
            /** @var array<string, mixed>|null $metadata */
            $metadata = isset($input['metadata']) && is_array($input['metadata']) ? $input['metadata'] : null;

            // Get customer ID
            $customerId = $this->getCurrentCustomerId();

            // Calculate cart total and currency from checkout state
            $cartTotal = isset($checkoutState['cartTotal']) ? (float) $checkoutState['cartTotal'] : null;
            $currency = isset($checkoutState['currency']) && is_string($checkoutState['currency']) ? $checkoutState['currency'] : null;

            // If not provided, calculate from cart items
            if ($cartTotal === null && !empty($checkoutState['cartItems']) && is_array($checkoutState['cartItems'])) {
                $cartTotal = 0.0;
                foreach ($checkoutState['cartItems'] as $item) {
                    if (is_array($item) && isset($item['price'], $item['quantity'])) {
                        $cartTotal += (float) $item['price'] * (float) $item['quantity'];
                    }
                }
            }

            // Emit CheckoutAbandonedEvent
            // @phpstan-ignore class.notFound
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

            $this->eventDispatcher->dispatch($event); // @phpstan-ignore argument.type

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
     *
     * @param array<string, mixed> $input
     * @return array<int, array{field: string, message: string}>
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
        /** @var array<string, mixed> $billingAddress */
        $billingAddress = $input['billingAddress'] ?? [];
        foreach ($required as $field) {
            if (empty($billingAddress[$field] ?? null)) {
                $errors[] = [
                    'field' => "billingAddress.$field",
                    'message' => "$field is required",
                ];
            }
        }

        // Validate email format
        if (isset($billingAddress['email'])) {
            $email = $billingAddress['email'];
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
     *
     * @param array<string, mixed> $input
     * @return array<int, array{field: string, message: string}>
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
