<?php

/**
 * Simple Stripe Payment Example - SOLID Architecture
 *
 * This example demonstrates how to create a payment using the StripeAdapter
 * with proper dependency injection (constructor injection, not setter injection).
 *
 * Compare this with stripe-raw/create-intent.php to see the architectural improvements:
 *
 * stripe-raw approach:
 *   $client = new StripeClient($secretKey);
 *   $intent = $client->paymentIntents->create([...]);
 *
 * stripe-wallet approach (SOLID):
 *   $adapter = $factory->createAdapter('stripe');
 *   $response = $adapter->createPayment($request);
 *
 * Benefits:
 * - Provider-agnostic (can switch to Unzer, PayPal, etc.)
 * - Type-safe request/response objects
 * - Testable with mock adapters
 * - No direct SDK dependency in business logic
 */

declare(strict_types=1);

// NOTE: This is a demonstration example. In production, use the OXID DI container
// to get the PaymentAdapterFactory instance instead of creating manually.

require_once __DIR__ . '/../vendor/autoload.php';

use OxidSolutionCatalysts\Payments\Component\Service\Factory\PaymentAdapterFactory;
use OxidSolutionCatalysts\Payments\Component\Adapter\Request\CreatePaymentRequest;
use OxidSolutionCatalysts\Payments\Stripe\Adapter\StripeClientFactory;
use OxidSolutionCatalysts\Payments\Stripe\Service\ModuleConfigurationService;

/**
 * Example 1: Create Payment with Direct Capture
 *
 * This is equivalent to stripe-raw/create-intent.php but uses the adapter pattern.
 */
function example1_createPaymentWithDirectCapture(): void
{
    echo "=== Example 1: Create Payment with Direct Capture ===\n\n";

    // Step 1: Setup (in production, get these from DI container)
    $configService = createMockConfigService();
    $clientFactory = new StripeClientFactory($configService);
    $factory = new PaymentAdapterFactory($configService, $clientFactory);

    // Step 2: Get adapter (provider-agnostic)
    $adapter = $factory->createAdapter('stripe');

    // Step 3: Create payment request (provider-agnostic DTO)
    $request = new CreatePaymentRequest(
        amount: 10.00,           // $10.00 in major units (adapter converts to cents)
        currency: 'USD',
        orderId: 'ORDER-123',
        shopId: '1',
        paymentMethod: 'card',
        directCapture: true,     // Capture immediately (vs authorize-then-capture)
        paymentMethodId: null,   // null = collect payment method on frontend
        customerId: null,        // null = one-time payment (not saved)
        returnUrl: 'https://shop.example.com/payment/return',
        metadata: [
            'customer_id' => 'user-456',
            'source' => 'web'
        ]
    );

    try {
        // Step 4: Create payment via adapter (Stripe SDK called internally)
        $response = $adapter->createPayment($request);

        echo "✅ Payment created successfully!\n\n";
        echo "Provider Payment ID: {$response->providerPaymentId}\n";
        echo "Status: {$response->status}\n";
        echo "Amount: {$response->amount} {$response->currency}\n";
        echo "Client Secret: {$response->clientSecret}\n\n";

        echo "📤 Send to frontend:\n";
        echo json_encode([
            'clientSecret' => $response->clientSecret,
            'status' => $response->status
        ], JSON_PRETTY_PRINT) . "\n\n";

        echo "💡 Frontend should use Stripe.js to confirm payment:\n";
        echo <<<'JAVASCRIPT'
const result = await stripe.confirmCardPayment(clientSecret, {
    payment_method: { card: cardElement }
});
JAVASCRIPT;
        echo "\n\n";

    } catch (\Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n\n";
    }
}

/**
 * Example 2: Two-Step Authorization (Authorize → Capture)
 *
 * Useful for scenarios where you need to verify availability before capturing.
 */
function example2_twoStepAuthorization(): void
{
    echo "=== Example 2: Two-Step Authorization ===\n\n";

    // Setup
    $configService = createMockConfigService();
    $clientFactory = new StripeClientFactory($configService);
    $factory = new PaymentAdapterFactory($configService, $clientFactory);
    $adapter = $factory->createAdapter('stripe');

    // Step 1: Authorize payment (hold funds)
    use OxidSolutionCatalysts\Payments\Component\Adapter\Request\AuthorizePaymentRequest;

    $authorizeRequest = new AuthorizePaymentRequest(
        amount: 50.00,
        currency: 'EUR',
        orderId: 'ORDER-456',
        shopId: '1',
        paymentMethod: 'card',
        paymentMethodId: null,  // Will be collected on frontend
        customerId: null,
        returnUrl: 'https://shop.example.com/payment/return',
        metadata: ['type' => 'pre-auth']
    );

    try {
        $authResponse = $adapter->authorizePayment($authorizeRequest);

        echo "✅ Payment authorized (funds on hold)!\n\n";
        echo "Authorization ID: {$authResponse->authorizationId}\n";
        echo "Status: {$authResponse->status}\n";
        echo "Authorized Amount: {$authResponse->amount} {$authResponse->currency}\n";
        echo "Expires At: {$authResponse->expiresAt->format('Y-m-d H:i:s')}\n\n";

        // Step 2: Later, capture the authorized payment
        use OxidSolutionCatalysts\Payments\Component\Adapter\Request\CaptureAuthorizationRequest;

        echo "💡 Later, after verifying stock/etc, capture the payment:\n\n";

        $captureRequest = new CaptureAuthorizationRequest(
            authorizationId: $authResponse->authorizationId,
            amount: null,  // null = capture full amount, or specify partial
            metadata: ['captured_by' => 'cron-job']
        );

        $captureResponse = $adapter->captureAuthorization($captureRequest);

        echo "✅ Payment captured!\n\n";
        echo "Capture ID: {$captureResponse->captureId}\n";
        echo "Captured Amount: {$captureResponse->amountCaptured} {$captureResponse->currency}\n";
        echo "Captured At: {$captureResponse->capturedAt->format('Y-m-d H:i:s')}\n\n";

    } catch (\Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n\n";
    }
}

/**
 * Example 3: Refund Payment
 */
function example3_refundPayment(): void
{
    echo "=== Example 3: Refund Payment ===\n\n";

    // Setup
    $configService = createMockConfigService();
    $clientFactory = new StripeClientFactory($configService);
    $factory = new PaymentAdapterFactory($configService, $clientFactory);
    $adapter = $factory->createAdapter('stripe');

    // First, create and capture a payment
    $createRequest = new CreatePaymentRequest(
        amount: 20.00,
        currency: 'USD',
        orderId: 'ORDER-789',
        shopId: '1',
        paymentMethod: 'card',
        directCapture: true,
        returnUrl: 'https://shop.example.com/payment/return',
        metadata: []
    );

    try {
        $paymentResponse = $adapter->createPayment($createRequest);
        echo "✅ Payment created: {$paymentResponse->providerPaymentId}\n\n";

        // Refund the payment (full or partial)
        use OxidSolutionCatalysts\Payments\Component\Adapter\Request\RefundPaymentRequest;

        $refundRequest = new RefundPaymentRequest(
            providerPaymentId: $paymentResponse->providerPaymentId,
            amount: 10.00,  // Partial refund ($10 of $20)
            reason: 'requested_by_customer',
            metadata: ['refunded_by' => 'admin-user']
        );

        $refundResponse = $adapter->refundPayment($refundRequest);

        echo "✅ Payment refunded!\n\n";
        echo "Refund ID: {$refundResponse->refundId}\n";
        echo "Refunded Amount: {$refundResponse->amountRefunded} {$refundResponse->currency}\n";
        echo "Refunded At: {$refundResponse->refundedAt->format('Y-m-d H:i:s')}\n";
        echo "Reason: {$refundResponse->reason}\n\n";

    } catch (\Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n\n";
    }
}

/**
 * Example 4: Get Payment Details
 */
function example4_getPaymentDetails(): void
{
    echo "=== Example 4: Get Payment Details ===\n\n";

    // Setup
    $configService = createMockConfigService();
    $clientFactory = new StripeClientFactory($configService);
    $factory = new PaymentAdapterFactory($configService, $clientFactory);
    $adapter = $factory->createAdapter('stripe');

    try {
        // Retrieve payment details by provider payment ID
        $paymentId = 'pi_test_example';  // Replace with real PaymentIntent ID
        $details = $adapter->getPaymentDetails($paymentId);

        echo "Payment Details:\n\n";
        echo "Provider Payment ID: {$details->providerPaymentId}\n";
        echo "Status: {$details->status}\n";
        echo "Amount: {$details->amount} {$details->currency}\n";
        echo "Amount Captured: {$details->amountCaptured} {$details->currency}\n";
        echo "Amount Refunded: {$details->amountRefunded} {$details->currency}\n";
        echo "Is Captured: " . ($details->isCaptured ? 'Yes' : 'No') . "\n";
        echo "Is Refunded: " . ($details->isRefunded ? 'Yes' : 'No') . "\n";
        echo "Is Cancelled: " . ($details->isCancelled ? 'Yes' : 'No') . "\n";
        echo "Created At: {$details->createdAt->format('Y-m-d H:i:s')}\n";

        if ($details->capturedAt !== null) {
            echo "Captured At: {$details->capturedAt->format('Y-m-d H:i:s')}\n";
        }

        echo "\n";

    } catch (\Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "\n\n";
    }
}

/**
 * Helper: Create mock configuration service
 *
 * In production, this would come from OXID module settings.
 */
function createMockConfigService(): ModuleConfigurationService
{
    // NOTE: Replace with real credentials from environment variables
    $secretKey = getenv('STRIPE_TEST_SECRET_KEY') ?: 'sk_test_your_key_here';
    $publishableKey = getenv('STRIPE_TEST_PUBLISHABLE_KEY') ?: 'pk_test_your_key_here';

    // For this example, we'll need to create a minimal mock implementation
    // In production, use the real ModuleConfigurationService with OXID settings
    return new class($secretKey, $publishableKey) extends ModuleConfigurationService {
        private string $secretKey;
        private string $publishableKey;

        public function __construct(string $secretKey, string $publishableKey)
        {
            $this->secretKey = $secretKey;
            $this->publishableKey = $publishableKey;
        }

        public function getToken(): string
        {
            return $this->secretKey;
        }

        public function getSecretKey(): string
        {
            return $this->secretKey;
        }

        public function getPublicKey(): string
        {
            return $this->publishableKey;
        }

        public function isTestMode(): bool
        {
            return str_starts_with($this->secretKey, 'sk_test_');
        }
    };
}

/**
 * Run all examples
 */
function runAllExamples(): void
{
    echo "\n";
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║  Stripe Payment Examples - SOLID Architecture                 ║\n";
    echo "║                                                                ║\n";
    echo "║  Demonstrates proper dependency injection and adapter pattern ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n";
    echo "\n";

    // Check if credentials are configured
    $secretKey = getenv('STRIPE_TEST_SECRET_KEY') ?: 'sk_test_your_key_here';

    if ($secretKey === 'sk_test_your_key_here') {
        echo "⚠️  WARNING: Stripe test credentials not configured!\n\n";
        echo "Set STRIPE_TEST_SECRET_KEY environment variable to run examples:\n";
        echo "export STRIPE_TEST_SECRET_KEY='sk_test_...'\n\n";
        echo "Get test keys from: https://dashboard.stripe.com/test/apikeys\n\n";
        return;
    }

    example1_createPaymentWithDirectCapture();
    echo str_repeat('-', 70) . "\n\n";

    example2_twoStepAuthorization();
    echo str_repeat('-', 70) . "\n\n";

    example3_refundPayment();
    echo str_repeat('-', 70) . "\n\n";

    example4_getPaymentDetails();
}

// Run examples if executed directly
if (basename($_SERVER['SCRIPT_FILENAME']) === 'simple-payment-example.php') {
    runAllExamples();
}
