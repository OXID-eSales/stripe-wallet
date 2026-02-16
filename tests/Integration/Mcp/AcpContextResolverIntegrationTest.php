<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Mcp;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\AgentContext;
use OxidEsales\PaymentComponent\Mcp\AgentContextInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for AcpContextResolverHandler — verifies the full ACP
 * checkout flow where buyer/items are resolved into OXID User + Basket
 * before contract creation.
 *
 * @group mcp-integration
 * @group checkout
 */
final class AcpContextResolverIntegrationTest extends TestCase
{
    private AcpCheckoutServiceInterface $checkoutService;
    private ContractRepositoryInterface $contractRepository;
    private AgentContextInterface $agentContext;

    /** @var list<string> Contract IDs to clean up */
    private array $createdContractIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $container = ContainerFactory::getInstance()->getContainer();
            $this->checkoutService = $container->get(AcpCheckoutServiceInterface::class);
            $this->contractRepository = $container->get(ContractRepositoryInterface::class);
        } catch (\Throwable $e) {
            $this->markTestSkipped('DI container not available: ' . $e->getMessage());
        }

        $this->agentContext = new AgentContext('acp-resolver-test', 'test-token');
    }

    protected function tearDown(): void
    {
        foreach ($this->createdContractIds as $id) {
            try {
                $contract = $this->contractRepository->findById($id);
                if ($contract !== null) {
                    $this->contractRepository->delete($id);
                }
            } catch (\Throwable) {
                // Best-effort cleanup
            }
        }

        parent::tearDown();
    }

    public function testCreateCheckoutResolvesUserByEmail(): void
    {
        $result = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs('integration-test@example.com'),
            $this->agentContext
        );

        if (isset($result['id'])) {
            $this->createdContractIds[] = $result['id'];
        }

        $this->assertArrayHasKey('id', $result, 'Contract should be created');
        $this->assertArrayNotHasKey('error', $result, 'Should not return error');

        $contract = $this->contractRepository->findById($result['id']);
        $this->assertNotNull($contract);
        $this->assertNotNull($contract->getUserId(), 'Contract must have a userId');
        $this->assertNotEmpty($contract->getUserId());
    }

    public function testCreateCheckoutCreatesGuestUser(): void
    {
        $uniqueEmail = 'acp-guest-' . uniqid() . '@test.example.com';
        $result = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs($uniqueEmail),
            $this->agentContext
        );

        if (isset($result['id'])) {
            $this->createdContractIds[] = $result['id'];
        }

        $this->assertArrayHasKey('id', $result, 'Contract should be created for guest');
        $this->assertArrayNotHasKey('error', $result);

        $contract = $this->contractRepository->findById($result['id']);
        $this->assertNotNull($contract);
        $this->assertNotEmpty($contract->getUserId());
    }

    public function testCreateCheckoutBuildsBasketFromItems(): void
    {
        $result = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs('basket-test@example.com'),
            $this->agentContext
        );

        if (isset($result['id'])) {
            $this->createdContractIds[] = $result['id'];
        }

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('line_items', $result);
        $this->assertNotEmpty($result['line_items']);
    }

    public function testFullCreateAndCancelFlow(): void
    {
        $result = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs('cancel-test@example.com'),
            $this->agentContext
        );

        if (isset($result['id'])) {
            $this->createdContractIds[] = $result['id'];
        }

        $this->assertArrayHasKey('id', $result);

        $cancelled = $this->checkoutService->cancelCheckout($result['id']);
        $this->assertSame('canceled', $cancelled['status']);

        $contract = $this->contractRepository->findById($result['id']);
        $this->assertSame('cancelled', $contract->getStateValue());
    }

    /**
     * @return array<string, mixed>
     */
    private function createCheckoutArgs(string $email = 'acp-test@example.com'): array
    {
        return [
            'items' => [
                ['id' => $this->getTestArticleId(), 'quantity' => 1],
            ],
            'buyer' => [
                'email' => $email,
                'first_name' => 'ACP',
                'last_name' => 'TestBuyer',
            ],
            'currency' => 'EUR',
        ];
    }

    private function getTestArticleId(): string
    {
        return 'dc5ffdf380e15674b56dd562a7cb6aec';
    }
}
