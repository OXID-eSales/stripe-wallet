<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\WebhookHandler;

use OxidEsales\PaymentComponent\Contract\ContractState;
use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookEvent;
use OxidEsales\PaymentComponent\Webhook\WebhookEventHandlerInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\WebhookHandler\SptTokenDeactivatedHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

/**
 * @covers \OxidEsales\Payments\Stripe\WebhookHandler\SptTokenDeactivatedHandler
 * @group sprint-50
 * @group webhook
 * @group handler
 */
final class SptTokenDeactivatedHandlerTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private FileLoggerInterface&MockObject $logger;
    private SptTokenDeactivatedHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->logger = $this->createMock(FileLoggerInterface::class);

        $this->handler = new SptTokenDeactivatedHandler(
            $this->contractRepository,
            $this->logger
        );
    }

    /**
     * @test
     */
    public function implementsInterface(): void
    {
        $this->assertInstanceOf(WebhookEventHandlerInterface::class, $this->handler);
    }

    /**
     * @test
     */
    public function supportsSptTokenDeactivatedEventType(): void
    {
        $this->assertTrue($this->handler->supports('shared_payment.granted_token.deactivated'));
    }

    /**
     * @test
     */
    public function doesNotSupportOtherEventTypes(): void
    {
        $this->assertFalse($this->handler->supports('shared_payment.granted_token.used'));
        $this->assertFalse($this->handler->supports('payment_intent.succeeded'));
        $this->assertFalse($this->handler->supports('charge.refunded'));
    }

    /**
     * @test
     */
    public function cancelsContractOnTokenDeactivation(): void
    {
        // Arrange
        $event = $this->createDeactivationEvent('contract_cancel_001', 'seller_revoked');

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_cancel_001');
        $contract->method('getState')->willReturn(ContractState::pending());

        $this->contractRepository
            ->method('findById')
            ->with('contract_cancel_001')
            ->willReturn($contract);

        // Expect cancel with reason
        $contract->expects($this->once())
            ->method('cancel')
            ->with('SPT token deactivated: seller_revoked');

        // Expect metadata updates
        $contract->expects($this->exactly(2))
            ->method('setMetadata')
            ->willReturnCallback(function (string $key, mixed $value): void {
                $validKeys = ['spt_deactivated_at', 'spt_deactivated_reason'];
                $this->assertContains($key, $validKeys);
            });

        $this->contractRepository
            ->expects($this->once())
            ->method('save')
            ->with($contract);

        // Act
        $result = $this->handler->handle($event);

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame('spt_deactivation_handled', $result->action);
    }

    /**
     * @test
     */
    public function doesNotCancelTerminalStateContract(): void
    {
        // Arrange
        $event = $this->createDeactivationEvent('contract_terminal', 'expired');

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_terminal');
        $contract->method('getState')->willReturn(ContractState::fulfilled());

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        // Expect metadata updates but NOT cancel
        $contract->expects($this->never())
            ->method('cancel');

        $contract->expects($this->exactly(2))
            ->method('setMetadata');

        $this->contractRepository
            ->expects($this->once())
            ->method('save');

        // Act
        $result = $this->handler->handle($event);

        // Assert
        $this->assertTrue($result->isSuccess());
    }

    /**
     * @test
     */
    public function doesNotCancelAlreadyCancelledContract(): void
    {
        // Arrange
        $event = $this->createDeactivationEvent('contract_already_cancelled', 'revoked');

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_already_cancelled');
        $contract->method('getState')->willReturn(ContractState::cancelled());

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        // Should NOT cancel again
        $contract->expects($this->never())
            ->method('cancel');

        // Act
        $result = $this->handler->handle($event);

        // Assert
        $this->assertTrue($result->isSuccess());
    }

    /**
     * @test
     */
    public function doesNotCancelFailedContract(): void
    {
        // Arrange
        $event = $this->createDeactivationEvent('contract_failed', 'revoked');

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_failed');
        $contract->method('getState')->willReturn(ContractState::failed());

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $contract->expects($this->never())
            ->method('cancel');

        // Act
        $result = $this->handler->handle($event);

        // Assert
        $this->assertTrue($result->isSuccess());
    }

    /**
     * @test
     */
    public function skipsWhenNoExternalIdPresent(): void
    {
        // Arrange
        $event = new WebhookEvent(
            'evt_no_ext',
            'shared_payment.granted_token.deactivated',
            ['object' => ['id' => 'spt_xxx', 'seller_details' => []]],
            time()
        );

        $this->contractRepository
            ->expects($this->never())
            ->method('findById');

        // Act
        $result = $this->handler->handle($event);

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
    }

    /**
     * @test
     */
    public function skipsWhenContractNotFound(): void
    {
        // Arrange
        $event = $this->createDeactivationEvent('contract_ghost', 'revoked');

        $this->contractRepository
            ->method('findById')
            ->with('contract_ghost')
            ->willReturn(null);

        // Act
        $result = $this->handler->handle($event);

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
    }

    /**
     * @test
     */
    public function storesDeactivationReasonInMetadata(): void
    {
        // Arrange
        $event = $this->createDeactivationEvent('contract_reason', 'buyer_cancelled');

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_reason');
        $contract->method('getState')->willReturn(ContractState::pending());

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $metadataCapture = [];
        $contract->method('setMetadata')
            ->willReturnCallback(function (string $key, mixed $value) use (&$metadataCapture): void {
                $metadataCapture[$key] = $value;
            });

        // Act
        $this->handler->handle($event);

        // Assert
        $this->assertSame('buyer_cancelled', $metadataCapture['spt_deactivated_reason']);
        $this->assertIsInt($metadataCapture['spt_deactivated_at']);
    }

    /**
     * @test
     */
    public function handlesUnknownDeactivationReasonGracefully(): void
    {
        // Arrange - event with no deactivated_reason field
        $event = new WebhookEvent(
            'evt_no_reason',
            'shared_payment.granted_token.deactivated',
            ['object' => [
                'id' => 'spt_no_reason',
                'seller_details' => ['external_id' => 'contract_no_reason'],
            ]],
            time()
        );

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_no_reason');
        $contract->method('getState')->willReturn(ContractState::pending());

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $metadataCapture = [];
        $contract->method('setMetadata')
            ->willReturnCallback(function (string $key, mixed $value) use (&$metadataCapture): void {
                $metadataCapture[$key] = $value;
            });

        // Expect cancel with 'unknown' reason
        $contract->expects($this->once())
            ->method('cancel')
            ->with('SPT token deactivated: unknown');

        // Act
        $result = $this->handler->handle($event);

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame('unknown', $metadataCapture['spt_deactivated_reason']);
    }

    /**
     * @test
     */
    public function cancelsCommittedContract(): void
    {
        // Arrange - committed is NOT terminal, so should be cancelled
        $event = $this->createDeactivationEvent('contract_committed', 'platform_revoked');

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_committed');
        $contract->method('getState')->willReturn(ContractState::committed());

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $contract->expects($this->once())
            ->method('cancel')
            ->with('SPT token deactivated: platform_revoked');

        // Act
        $result = $this->handler->handle($event);

        // Assert
        $this->assertTrue($result->isSuccess());
    }

    /**
     * @test
     */
    public function logsCancellationForNonTerminalContract(): void
    {
        // Arrange
        $event = $this->createDeactivationEvent('contract_log_cancel', 'revoked');

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_log_cancel');
        $contract->method('getState')->willReturn(ContractState::pending());

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('log')
            ->with(
                $this->stringContains('SptTokenDeactivated'),
                $this->isType('array')
            );

        // Act
        $this->handler->handle($event);
    }

    private function createDeactivationEvent(string $externalId, string $reason): WebhookEvent
    {
        return new WebhookEvent(
            'evt_deact_' . substr(md5($externalId), 0, 8),
            'shared_payment.granted_token.deactivated',
            ['object' => [
                'id' => 'spt_deact_' . substr(md5($externalId), 0, 6),
                'seller_details' => ['external_id' => $externalId],
                'deactivated_reason' => $reason,
            ]],
            time()
        );
    }
}
