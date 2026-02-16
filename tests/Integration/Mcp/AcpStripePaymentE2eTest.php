<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\AgentContextInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Stripe\StripeClient;

/**
 * End-to-end tests for ACP checkout with real Stripe payments.
 *
 * Exercises: create_checkout -> complete_checkout -> verify order creation
 * through the real OXID DI container and Stripe test API.
 *
 * @group sprint-56
 * @group mcp-integration
 * @group external-api
 */
final class AcpStripePaymentE2eTest extends TestCase
{
    private AcpCheckoutServiceInterface $checkoutService;
    private ContractRepositoryInterface $contractRepository;
    private AgentContextInterface $agentContext;
    private StripeClient $stripeClient;

    /** @var list<string> Contract IDs to clean up */
    private array $createdContractIds = [];

    /** @var list<string> PaymentIntent IDs to cancel */
    private array $createdPaymentIntentIds = [];

    /** @var list<string> PaymentMethod IDs to detach */
    private array $createdPaymentMethodIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $stripeKey = getenv('STRIPE_TEST_SECRET_KEY');
        if (empty($stripeKey)) {
            $this->markTestSkipped('STRIPE_TEST_SECRET_KEY not set — skipping ACP Stripe E2E test');
        }

        try {
            $container = ContainerFactory::getInstance()->getContainer();
            $this->checkoutService = $container->get(AcpCheckoutServiceInterface::class);
            $this->contractRepository = $container->get(ContractRepositoryInterface::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('ACP checkout services not available: ' . $e->getMessage());
        }

        $this->stripeClient = new StripeClient($stripeKey);
        $this->agentContext = new AgentContext('e2e-stripe-test-agent', 'test-token');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdPaymentIntentIds as $piId) {
            try {
                $pi = $this->stripeClient->paymentIntents->retrieve($piId);
                if (in_array($pi->status, ['requires_payment_method', 'requires_confirmation', 'requires_action', 'requires_capture'])) {
                    $this->stripeClient->paymentIntents->cancel($piId);
                }
            } catch (\Throwable) {
                // Best-effort cleanup
            }
        }

        foreach ($this->createdPaymentMethodIds as $pmId) {
            try {
                $this->stripeClient->paymentMethods->detach($pmId);
            } catch (\Throwable) {
                // Best-effort cleanup
            }
        }

        foreach ($this->createdContractIds as $contractId) {
            try {
                $contract = $this->contractRepository->findById($contractId);
                if ($contract !== null) {
                    $this->contractRepository->delete($contractId);
                }
            } catch (\Throwable) {
                // Best-effort cleanup
            }
        }

        parent::tearDown();
    }

    public function testFullCheckoutWithStripePayment(): void
    {
        // 1. Create PaymentMethod via Stripe API (tok_visa = always succeeds)
        $pm = $this->stripeClient->paymentMethods->create([
            'type' => 'card',
            'card' => ['token' => 'tok_visa'],
        ]);
        $this->createdPaymentMethodIds[] = $pm->id;

        // 2. Create checkout via ACP
        $created = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );

        $this->assertArrayHasKey('id', $created, 'create_checkout must return a checkout ID');
        $checkoutId = $created['id'];
        $this->createdContractIds[] = $checkoutId;

        // 3. Complete checkout with Stripe payment method
        $result = $this->checkoutService->completeCheckout(
            $checkoutId,
            ['token' => $pm->id, 'provider' => 'stripe'],
            $this->agentContext
        );

        // 4. Verify success: result has order_id and permalink_url (no error)
        $this->assertArrayNotHasKey('error', $result, 'completeCheckout should not return an error: '
            . json_encode($result));
        $this->assertArrayHasKey('order_id', $result);
        $this->assertNotEmpty($result['order_id']);

        if (isset($result['permalink_url'])) {
            $this->assertStringContainsString('cl=order_confirm', $result['permalink_url']);
        }

        // 5. Verify contract state in database
        $contract = $this->contractRepository->findById($checkoutId);
        $this->assertNotNull($contract, 'Contract must exist in database');
        $this->assertNotNull($contract->getOrderId(), 'Contract must have order ID after completion');

        // Track PI for cleanup (extract from provider data if available)
        $providerOrderId = $contract->getProviderOrderId();
        if ($providerOrderId !== null && str_starts_with($providerOrderId, 'pi_')) {
            $this->createdPaymentIntentIds[] = $providerOrderId;
        }
    }

    public function testCheckoutWithInvalidTokenReturnsError(): void
    {
        // Create checkout
        $created = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );

        $this->assertArrayHasKey('id', $created);
        $checkoutId = $created['id'];
        $this->createdContractIds[] = $checkoutId;

        // Attempt completion with bogus token
        $result = $this->checkoutService->completeCheckout(
            $checkoutId,
            ['token' => 'pm_invalid_bogus_token', 'provider' => 'stripe'],
            $this->agentContext
        );

        // Should return error, not crash
        $this->assertArrayHasKey('error', $result, 'Invalid token must produce an error response');

        // Contract should NOT have an order ID
        $contract = $this->contractRepository->findById($checkoutId);
        $this->assertNotNull($contract);
        $this->assertNull($contract->getOrderId(), 'No order should be created for failed payment');
    }

    public function testCheckoutWithDeclinedCardFails(): void
    {
        // tok_chargeDeclined creates a PM that will be declined
        $pm = $this->stripeClient->paymentMethods->create([
            'type' => 'card',
            'card' => ['token' => 'tok_chargeDeclined'],
        ]);
        $this->createdPaymentMethodIds[] = $pm->id;

        // Create checkout
        $created = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );

        $this->assertArrayHasKey('id', $created);
        $checkoutId = $created['id'];
        $this->createdContractIds[] = $checkoutId;

        // Attempt completion with declined card
        $result = $this->checkoutService->completeCheckout(
            $checkoutId,
            ['token' => $pm->id, 'provider' => 'stripe'],
            $this->agentContext
        );

        // Should return error for declined card
        $this->assertArrayHasKey('error', $result, 'Declined card must produce an error response');

        // Contract should NOT have an order ID
        $contract = $this->contractRepository->findById($checkoutId);
        $this->assertNotNull($contract);
        $this->assertNull($contract->getOrderId(), 'No order should be created for declined payment');
    }

    /**
     * @return array<string, mixed>
     */
    private function createCheckoutArgs(): array
    {
        return [
            'items' => [
                ['id' => $this->getTestArticleId(), 'quantity' => 1],
            ],
            'buyer' => [
                'email' => 'e2e-stripe-test@example.com',
                'first_name' => 'E2E',
                'last_name' => 'Stripe',
            ],
            'currency' => 'EUR',
        ];
    }

    private function getTestArticleId(): string
    {
        return 'dc5ffdf380e15674b56dd562a7cb6aec';
    }
}
