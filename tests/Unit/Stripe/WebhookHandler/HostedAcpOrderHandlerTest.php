<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\WebhookHandler;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookEvent;
use OxidEsales\PaymentComponent\Webhook\WebhookEventHandlerInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\WebhookHandler\HostedAcpOrderHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

/**
 * Unit tests for HostedAcpOrderHandler.
 *
 * Tests webhook handling for hosted ACP checkout session completion,
 * including metadata storage on the contract.
 *
 * @covers \OxidEsales\Payments\Stripe\WebhookHandler\HostedAcpOrderHandler
 */
class HostedAcpOrderHandlerTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private FileLoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->logger = $this->createMock(FileLoggerInterface::class);
    }

    private function createHandler(): HostedAcpOrderHandler
    {
        return new HostedAcpOrderHandler(
            $this->contractRepository,
            $this->logger
        );
    }

    private function createWebhookEvent(
        string $type = 'checkout_session.completed',
        array $objectData = []
    ): WebhookEvent {
        return new WebhookEvent(
            'evt_test_123',
            $type,
            ['object' => $objectData],
            time()
        );
    }

    public function testImplementsInterface(): void
    {
        $handler = $this->createHandler();

        $this->assertInstanceOf(WebhookEventHandlerInterface::class, $handler);
    }

    // ==========================================
    // supports() tests
    // ==========================================

    public function testSupportsReturnsTrueForCheckoutSessionCompleted(): void
    {
        $handler = $this->createHandler();

        $this->assertTrue($handler->supports('checkout_session.completed'));
    }

    public function testSupportsReturnsFalseForOtherEventTypes(): void
    {
        $handler = $this->createHandler();

        $this->assertFalse($handler->supports('payment_intent.succeeded'));
        $this->assertFalse($handler->supports('charge.succeeded'));
        $this->assertFalse($handler->supports('checkout_session.expired'));
    }

    // ==========================================
    // handle() - non-agentic sessions
    // ==========================================

    public function testNonAgenticSessionIsSkipped(): void
    {
        $event = $this->createWebhookEvent('checkout_session.completed', [
            'id' => 'cs_test_regular',
            'metadata' => ['source' => 'website'],
        ]);

        $this->contractRepository
            ->expects($this->never())
            ->method('findById');

        $handler = $this->createHandler();
        $result = $handler->handle($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Not an agentic commerce session', $result->error);
    }

    public function testSessionWithNoMetadataIsSkipped(): void
    {
        $event = $this->createWebhookEvent('checkout_session.completed', [
            'id' => 'cs_test_no_metadata',
        ]);

        $handler = $this->createHandler();
        $result = $handler->handle($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
    }

    public function testSessionWithEmptyMetadataIsSkipped(): void
    {
        $event = $this->createWebhookEvent('checkout_session.completed', [
            'id' => 'cs_test_empty',
            'metadata' => [],
        ]);

        $handler = $this->createHandler();
        $result = $handler->handle($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
    }

    // ==========================================
    // handle() - missing contract_id
    // ==========================================

    public function testMissingContractIdReturnsFailure(): void
    {
        $event = $this->createWebhookEvent('checkout_session.completed', [
            'id' => 'cs_test_no_contract',
            'metadata' => ['source' => 'agentic_commerce'],
        ]);

        $handler = $this->createHandler();
        $result = $handler->handle($event);

        $this->assertTrue($result->isFailure());
        $this->assertSame('error', $result->action);
        $this->assertSame('Missing contract_id in hosted ACP metadata', $result->error);
    }

    // ==========================================
    // handle() - contract not found
    // ==========================================

    public function testContractNotFoundIsSkipped(): void
    {
        $event = $this->createWebhookEvent('checkout_session.completed', [
            'id' => 'cs_test_orphan',
            'metadata' => [
                'source' => 'agentic_commerce',
                'contract_id' => 'contract_nonexistent',
            ],
        ]);

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with('contract_nonexistent')
            ->willReturn(null);

        $handler = $this->createHandler();
        $result = $handler->handle($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
        $this->assertSame('Contract not found for hosted ACP order', $result->error);
    }

    // ==========================================
    // handle() - success case
    // ==========================================

    public function testSuccessCaseStoresMetadataOnContract(): void
    {
        $contractId = 'contract_hosted_123';
        $contract = $this->createMock(PaymentContractInterface::class);

        $event = $this->createWebhookEvent('checkout_session.completed', [
            'id' => 'cs_test_success_456',
            'payment_intent' => 'pi_hosted_789',
            'customer_details' => ['email' => 'buyer@example.com'],
            'metadata' => [
                'source' => 'agentic_commerce',
                'contract_id' => $contractId,
            ],
        ]);

        $this->contractRepository
            ->expects($this->once())
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $contract->expects($this->exactly(3))
            ->method('setMetadata')
            ->willReturnCallback(function (string $key, mixed $value) {
                static $callIndex = 0;
                if ($callIndex === 0) {
                    $this->assertSame('hosted_checkout_session_id', $key);
                    $this->assertSame('cs_test_success_456', $value);
                }
                if ($callIndex === 1) {
                    $this->assertSame('hosted_payment_intent', $key);
                    $this->assertSame('pi_hosted_789', $value);
                }
                if ($callIndex === 2) {
                    $this->assertSame('hosted_customer_email', $key);
                    $this->assertSame('buyer@example.com', $value);
                }
                $callIndex++;
            });

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $handler = $this->createHandler();
        $result = $handler->handle($event);

        $this->assertTrue($result->isSuccess());
        $this->assertSame('hosted_acp_order_processed', $result->action);
    }

    public function testSuccessCaseHandlesMissingOptionalFields(): void
    {
        $contractId = 'contract_minimal_123';
        $contract = $this->createMock(PaymentContractInterface::class);

        $event = $this->createWebhookEvent('checkout_session.completed', [
            'metadata' => [
                'source' => 'agentic_commerce',
                'contract_id' => $contractId,
            ],
        ]);

        $this->contractRepository
            ->method('findById')
            ->with($contractId)
            ->willReturn($contract);

        $contract->expects($this->exactly(3))
            ->method('setMetadata')
            ->willReturnCallback(function (string $key, mixed $value) {
                static $callIndex = 0;
                if ($callIndex === 0) {
                    $this->assertSame('hosted_checkout_session_id', $key);
                    $this->assertSame('', $value);
                }
                if ($callIndex === 1) {
                    $this->assertSame('hosted_payment_intent', $key);
                    $this->assertSame('', $value);
                }
                if ($callIndex === 2) {
                    $this->assertSame('hosted_customer_email', $key);
                    $this->assertSame('', $value);
                }
                $callIndex++;
            });

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        $handler = $this->createHandler();
        $result = $handler->handle($event);

        $this->assertTrue($result->isSuccess());
    }

    public function testSuccessCaseLogsProcessedEvent(): void
    {
        $contractId = 'contract_log_test';
        $contract = $this->createMock(PaymentContractInterface::class);

        $event = $this->createWebhookEvent('checkout_session.completed', [
            'id' => 'cs_log_test',
            'metadata' => [
                'source' => 'agentic_commerce',
                'contract_id' => $contractId,
            ],
        ]);

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $this->logger
            ->expects($this->once())
            ->method('log')
            ->with(
                'HostedAcpOrder: processed',
                $this->callback(function (array $context) use ($contractId) {
                    return ($context['contractId'] ?? '') === $contractId
                        && ($context['sessionId'] ?? '') === 'cs_log_test';
                })
            );

        $handler = $this->createHandler();
        $handler->handle($event);
    }
}
