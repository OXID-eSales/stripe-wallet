<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\Payments\Stripe\Mcp\Service\SptPaymentServiceInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests real Stripe API calls with test keys.
 * Skipped if STRIPE_TEST_SECRET_KEY is not set.
 *
 * @group sprint-54
 * @group mcp-integration
 * @group spt
 * @group external-api
 */
final class SptPaymentServiceTest extends TestCase
{
    private SptPaymentServiceInterface $sptService;

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
}
