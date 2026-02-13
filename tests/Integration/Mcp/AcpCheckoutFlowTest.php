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
 * Full ACP checkout lifecycle — create -> get -> update -> cancel — exercising
 * the real contract repository and database.
 *
 * @group sprint-54
 * @group mcp-integration
 * @group checkout
 */
final class AcpCheckoutFlowTest extends TestCase
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
            $this->markTestSkipped('ACP checkout services not available: ' . $e->getMessage());
        }

        $this->agentContext = new AgentContext('e2e-test-agent', 'test-token');
    }

    protected function tearDown(): void
    {
        // Clean up test contracts
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

    public function testCreateCheckoutReturnsContractWithId(): void
    {
        $result = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );

        if (isset($result['id'])) {
            $this->createdContractIds[] = $result['id'];
        }

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayHasKey('line_items', $result);
        $this->assertNotEmpty($result['line_items']);
    }

    public function testGetCheckoutReturnsExistingContract(): void
    {
        $created = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );
        $this->createdContractIds[] = $created['id'];

        $retrieved = $this->checkoutService->getCheckout($created['id']);

        $this->assertSame($created['id'], $retrieved['id']);
        $this->assertArrayHasKey('line_items', $retrieved);
    }

    public function testGetCheckoutNotFoundReturnsError(): void
    {
        $result = $this->checkoutService->getCheckout('nonexistent-contract-id');

        $this->assertArrayHasKey('error', $result);
    }

    public function testUpdateCheckoutStoresMetadata(): void
    {
        $created = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );
        $this->createdContractIds[] = $created['id'];

        $updated = $this->checkoutService->updateCheckout(
            $created['id'],
            ['selected_fulfillment_option_id' => 'shipping_standard'],
            $this->agentContext
        );

        $this->assertSame($created['id'], $updated['id']);

        $contract = $this->contractRepository->findById($created['id']);
        $this->assertNotNull($contract);
        $this->assertSame(
            'shipping_standard',
            $contract->getMetadata('acp_selected_fulfillment_option_id')
        );
    }

    public function testCancelCheckoutTransitionsContract(): void
    {
        $created = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );
        $this->createdContractIds[] = $created['id'];

        $cancelled = $this->checkoutService->cancelCheckout($created['id']);

        $this->assertSame('canceled', $cancelled['status']);

        $contract = $this->contractRepository->findById($created['id']);
        $this->assertSame('cancelled', $contract->getStateValue());
    }

    public function testDoubleCancelReturnsError(): void
    {
        $created = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );
        $this->createdContractIds[] = $created['id'];

        $this->checkoutService->cancelCheckout($created['id']);
        $result = $this->checkoutService->cancelCheckout($created['id']);

        $this->assertArrayHasKey('error', $result);
    }

    public function testCompleteCheckoutWithoutTokenReturnsError(): void
    {
        $created = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );
        $this->createdContractIds[] = $created['id'];

        $result = $this->checkoutService->completeCheckout(
            $created['id'],
            ['token' => '', 'provider' => 'stripe'],
            $this->agentContext
        );

        $this->assertArrayHasKey('error', $result);
    }

    public function testAgentIdStoredInContractMetadata(): void
    {
        $created = $this->checkoutService->createCheckout(
            $this->createCheckoutArgs(),
            $this->agentContext
        );
        $this->createdContractIds[] = $created['id'];

        $contract = $this->contractRepository->findById($created['id']);
        $this->assertSame('e2e-test-agent', $contract->getMetadata('acp_agent_id'));
    }

    /** @return array<string, mixed> */
    private function createCheckoutArgs(): array
    {
        return [
            'items' => [
                ['id' => $this->getTestArticleId(), 'quantity' => 1],
            ],
            'buyer' => [
                'email' => 'integration-test@example.com',
                'first_name' => 'Integration',
                'last_name' => 'Test',
            ],
            'currency' => 'EUR',
        ];
    }

    private function getTestArticleId(): string
    {
        return 'dc5ffdf380e15674b56dd562a7cb6aec';
    }
}
