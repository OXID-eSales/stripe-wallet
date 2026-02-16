<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\Payments\Stripe\Mcp\Service\SptPaymentServiceInterface;
use PHPUnit\Framework\TestCase;
use Stripe\StripeClient;

/**
 * Tests real Stripe API calls with test keys.
 * Skipped if STRIPE_TEST_SECRET_KEY is not set.
 *
 * @group sprint-54
 * @group sprint-56
 * @group mcp-integration
 * @group spt
 * @group external-api
 */
final class SptPaymentServiceTest extends TestCase
{
    private SptPaymentServiceInterface $sptService;
    private ?StripeClient $stripeClient = null;

    /** @var list<string> PaymentIntent IDs to cancel */
    private array $createdPaymentIntentIds = [];

    /** @var list<string> PaymentMethod IDs to detach */
    private array $createdPaymentMethodIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $stripeKey = getenv('STRIPE_TEST_SECRET_KEY');
        if (empty($stripeKey)) {
            $this->markTestSkipped('STRIPE_TEST_SECRET_KEY not set — skipping SPT integration test');
        }

        try {
            $container = ContainerFactory::getInstance()->getContainer();
            $this->sptService = $container->get(SptPaymentServiceInterface::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('SPT service not available: ' . $e->getMessage());
        }

        $this->stripeClient = new StripeClient($stripeKey);
    }

    protected function tearDown(): void
    {
        if ($this->stripeClient !== null) {
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
        }

        parent::tearDown();
    }

    public function testConfirmWithInvalidTokenReturnsFailure(): void
    {
        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('test-contract-spt');
        $contract->method('getAmount')->willReturn(1999);
        $contract->method('getCurrency')->willReturn('EUR');

        $result = $this->sptService->confirmWithSpt($contract, 'spt_invalid_token', []);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->getErrorMessage());
    }

    public function testConfirmWithValidPaymentMethodSucceeds(): void
    {
        $pm = $this->stripeClient->paymentMethods->create([
            'type' => 'card',
            'card' => ['token' => 'tok_visa'],
        ]);
        $this->createdPaymentMethodIds[] = $pm->id;

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('test-contract-spt-valid');
        $contract->method('getAmount')->willReturn(19.99);
        $contract->method('getCurrency')->willReturn('EUR');
        $contract->method('getOrderId')->willReturn(null);

        $result = $this->sptService->confirmWithSpt($contract, $pm->id);

        $this->assertTrue($result->isSuccessful(), 'Payment must succeed: ' . ($result->getErrorMessage() ?? ''));
        $this->assertNotNull($result->getPaymentIntentId());
        $this->assertStringStartsWith('pi_', $result->getPaymentIntentId());

        $this->createdPaymentIntentIds[] = $result->getPaymentIntentId();
    }

    public function testConfirmWithDeclinedCardReturnsFailed(): void
    {
        $pm = $this->stripeClient->paymentMethods->create([
            'type' => 'card',
            'card' => ['token' => 'tok_chargeDeclined'],
        ]);
        $this->createdPaymentMethodIds[] = $pm->id;

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('test-contract-spt-declined');
        $contract->method('getAmount')->willReturn(19.99);
        $contract->method('getCurrency')->willReturn('EUR');
        $contract->method('getOrderId')->willReturn(null);

        $result = $this->sptService->confirmWithSpt($contract, $pm->id);

        $this->assertFalse($result->isSuccessful());
        $this->assertNotNull($result->getErrorMessage());
    }
}
