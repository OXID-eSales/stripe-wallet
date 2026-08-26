<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\Return\CheckoutReturnRejection;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Helper that exposes the logger seam so the log line can be inspected without
 * a booted shop.
 */
final class LoggingControllerRequestHelper extends ControllerRequestHelper
{
    public function __construct(
        TokenServiceInterface $tokenService,
        ModuleConfigurationServiceInterface $moduleConfig,
        private readonly LoggerInterface $testLogger,
    ) {
        parent::__construct($tokenService, $moduleConfig);
    }

    protected function logger(): LoggerInterface
    {
        return $this->testLogger;
    }
}

/**
 * A refused Stripe return used to leave no trace at all: the customer saw
 * "Payment verification failed" and the shop log stayed empty, so there was
 * nothing to debug from. The reason now has to reach the log.
 */
#[CoversClass(ControllerRequestHelper::class)]
final class ControllerRequestHelperReturnLoggingTest extends TestCase
{
    /** @param array<string, mixed> $context */
    private function helper(LoggerInterface $logger): LoggingControllerRequestHelper
    {
        return new LoggingControllerRequestHelper(
            $this->createMock(TokenServiceInterface::class),
            $this->createMock(ModuleConfigurationServiceInterface::class),
            $logger
        );
    }

    public function testRejectedReturnIsLoggedWithItsReason(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->stringContains('checkout return rejected'),
                $this->callback(
                    static fn (array $context): bool => ($context['reason'] ?? null) === 'contract_mismatch'
                )
            );

        $this->helper($logger)->logReturnRejected(CheckoutReturnRejection::ContractMismatch);
    }

    public function testExtraContextIsPassedThrough(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->anything(),
                $this->callback(static function (array $context): bool {
                    return ($context['reason'] ?? null) === 'invalid_contract_token'
                        && ($context['contractId'] ?? null) === 'contract-1';
                })
            );

        $this->helper($logger)->logReturnRejected(
            CheckoutReturnRejection::InvalidContractToken,
            ['contractId' => 'contract-1']
        );
    }

    /**
     * The log line must never carry the token itself — it is a credential.
     */
    public function testCallerSuppliedReasonCannotOverrideTheRealOne(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->with(
                $this->anything(),
                $this->callback(
                    static fn (array $context): bool => ($context['reason'] ?? null) === 'no_order_created'
                )
            );

        $this->helper($logger)->logReturnRejected(
            CheckoutReturnRejection::NoOrderCreated,
            ['reason' => 'something else entirely']
        );
    }
}
