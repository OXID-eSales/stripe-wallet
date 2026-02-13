<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\WebhookHandler;

use OxidEsales\PaymentComponent\Contract\PaymentContractInterface;
use OxidEsales\PaymentComponent\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookEvent;
use OxidEsales\PaymentComponent\Webhook\WebhookEventHandlerInterface;
use OxidEsales\PaymentComponent\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\WebhookHandler\SptTokenUsedHandler;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;

/**
 * @covers \OxidEsales\Payments\Stripe\WebhookHandler\SptTokenUsedHandler
 * @group sprint-50
 * @group webhook
 * @group handler
 */
final class SptTokenUsedHandlerTest extends TestCase
{
    private ContractRepositoryInterface&MockObject $contractRepository;
    private FileLoggerInterface&MockObject $logger;
    private SptTokenUsedHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->logger = $this->createMock(FileLoggerInterface::class);

        $this->handler = new SptTokenUsedHandler(
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
    public function supportsSptTokenUsedEventType(): void
    {
        $this->assertTrue($this->handler->supports('shared_payment.granted_token.used'));
    }

    /**
     * @test
     */
    public function doesNotSupportOtherEventTypes(): void
    {
        $this->assertFalse($this->handler->supports('shared_payment.granted_token.deactivated'));
        $this->assertFalse($this->handler->supports('payment_intent.succeeded'));
        $this->assertFalse($this->handler->supports('charge.refunded'));
    }

    /**
     * @test
     */
    public function updatesContractMetadataOnTokenUsed(): void
    {
        // Arrange
        $event = $this->createSptEvent('spt_token_abc', 'contract_ext_123', [
            'id' => 'spt_token_abc',
            'payment_method' => [
                'card' => [
                    'brand' => 'visa',
                    'last4' => '4242',
                ],
            ],
        ]);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_ext_123');

        $this->contractRepository
            ->method('findById')
            ->with('contract_ext_123')
            ->willReturn($contract);

        // Expect metadata updates
        $contract->expects($this->exactly(4))
            ->method('setMetadata')
            ->willReturnCallback(function (string $key, mixed $value): void {
                $validKeys = ['spt_token_id', 'spt_used_at', 'spt_card_brand', 'spt_card_last4'];
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
        $this->assertSame('spt_metadata_updated', $result->action);
    }

    /**
     * @test
     */
    public function skipsWhenNoExternalIdPresent(): void
    {
        // Arrange - event with no external_id
        $event = new WebhookEvent(
            'evt_no_ext_id',
            'shared_payment.granted_token.used',
            ['object' => ['id' => 'spt_xyz', 'seller_details' => []]],
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
        $event = $this->createSptEvent('spt_orphan', 'contract_nonexistent');

        $this->contractRepository
            ->method('findById')
            ->with('contract_nonexistent')
            ->willReturn(null);

        $this->contractRepository
            ->expects($this->never())
            ->method('save');

        // Act
        $result = $this->handler->handle($event);

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame('skipped', $result->action);
    }

    /**
     * @test
     */
    public function storesCardBrandAndLast4InMetadata(): void
    {
        // Arrange
        $event = $this->createSptEvent('spt_card_test', 'contract_card', [
            'id' => 'spt_card_test',
            'payment_method' => [
                'card' => [
                    'brand' => 'mastercard',
                    'last4' => '5678',
                ],
            ],
        ]);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_card');

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
        $this->assertSame('spt_card_test', $metadataCapture['spt_token_id']);
        $this->assertSame('mastercard', $metadataCapture['spt_card_brand']);
        $this->assertSame('5678', $metadataCapture['spt_card_last4']);
        $this->assertIsInt($metadataCapture['spt_used_at']);
    }

    /**
     * @test
     */
    public function handlesEmptyCardDataGracefully(): void
    {
        // Arrange
        $event = $this->createSptEvent('spt_no_card', 'contract_no_card', [
            'id' => 'spt_no_card',
            'payment_method' => [],
        ]);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_no_card');

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $metadataCapture = [];
        $contract->method('setMetadata')
            ->willReturnCallback(function (string $key, mixed $value) use (&$metadataCapture): void {
                $metadataCapture[$key] = $value;
            });

        // Act
        $result = $this->handler->handle($event);

        // Assert
        $this->assertTrue($result->isSuccess());
        $this->assertSame('', $metadataCapture['spt_card_brand']);
        $this->assertSame('', $metadataCapture['spt_card_last4']);
    }

    /**
     * @test
     */
    public function logsTokenUsageInfo(): void
    {
        // Arrange
        $event = $this->createSptEvent('spt_log_test', 'contract_log', [
            'id' => 'spt_log_test',
        ]);

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getId')->willReturn('contract_log');

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $this->logger
            ->expects($this->atLeastOnce())
            ->method('log')
            ->with(
                $this->stringContains('SptTokenUsed'),
                $this->isType('array')
            );

        // Act
        $this->handler->handle($event);
    }

    /**
     * @param array<string, mixed> $objectOverrides
     */
    private function createSptEvent(
        string $tokenId,
        string $externalId,
        array $objectOverrides = []
    ): WebhookEvent {
        $objectData = array_merge(
            [
                'id' => $tokenId,
                'seller_details' => ['external_id' => $externalId],
                'payment_method' => ['card' => ['brand' => 'visa', 'last4' => '4242']],
            ],
            $objectOverrides
        );

        // Ensure seller_details.external_id is always set from $externalId
        if (!isset($objectOverrides['seller_details'])) {
            $objectData['seller_details'] = ['external_id' => $externalId];
        }

        return new WebhookEvent(
            'evt_spt_' . substr(md5($tokenId), 0, 8),
            'shared_payment.granted_token.used',
            ['object' => $objectData],
            time()
        );
    }
}
