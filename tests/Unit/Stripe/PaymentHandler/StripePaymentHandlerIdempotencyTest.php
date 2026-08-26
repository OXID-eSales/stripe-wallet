<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\PaymentHandler;

use OxidEsales\PaymentBase\Adapter\PaymentContextInterface;
use OxidEsales\PaymentBase\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentBase\Adapter\ShopOrderServiceInterface;
use OxidEsales\PaymentBase\Contract\BasketSnapshot;
use OxidEsales\PaymentBase\Contract\ContractState;
use OxidEsales\PaymentBase\Contract\PaymentContractInterface;
use OxidEsales\PaymentBase\Repository\ContractRepositoryInterface;
use OxidEsales\PaymentBase\Service\ContractServiceInterface;
use OxidEsales\PaymentBase\Service\IframeCheckoutSettingsInterface;
use OxidEsales\Payments\Stripe\PaymentHandler\StripePaymentHandler;
use OxidEsales\Payments\Stripe\Service\CheckoutSessionServiceInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * OPC-175 / OPC-176 — `processPayment()` must not create a second contract and
 * a second shop order for the same basket.
 *
 * WHY THIS EXISTS. `processPayment()` used to call `createContract()` on every
 * invocation, and its step 2 finalises a shop order — number, stock,
 * confirmation mail. OPC's footer widget reaches `processCheckout` from an eager
 * mount that fires more than once, so one shopper produced one contract and one
 * complete order PER CALL. Traced on pay1 2026-08-27 with a backtrace on
 * `Order::finalizeOrder()`: two finalisations 21 seconds apart from an identical
 * stack. The database held 14 contracts inside one hour, every one `pending`,
 * each with its own OXORDERID.
 *
 * The reuse rule is deliberately narrower than `findActiveByUserId()`, which
 * also returns `ready_to_commit` and `committed` contracts. The
 * committed/ready cases are the dangerous ones and have their own tests below:
 * attaching a new purchase to a contract whose payment is already in flight
 * would bill the shopper for the wrong thing.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(StripePaymentHandler::class)]
final class StripePaymentHandlerIdempotencyTest extends TestCase
{
    private ContractRepositoryInterface $contractRepository;

    protected function setUp(): void
    {
        parent::setUp();
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
    }

    private function handler(): StripePaymentHandler
    {
        return new StripePaymentHandler(
            $this->createMock(ContractServiceInterface::class),
            $this->createMock(CheckoutSessionServiceInterface::class),
            $this->contractRepository,
            $this->createMock(ShopAdapterInterface::class),
            $this->createMock(ShopOrderServiceInterface::class),
            $this->createMock(ModuleConfigurationServiceInterface::class),
            $this->createMock(TokenServiceInterface::class),
            new NullLogger(),
            null,
            $this->createMock(IframeCheckoutSettingsInterface::class)
        );
    }

    /** Reach the private decision without booting a session or a shop. */
    private function findReusable(PaymentContextInterface $context): ?PaymentContractInterface
    {
        $method = new \ReflectionMethod(StripePaymentHandler::class, 'findReusableContract');

        return $method->invoke($this->handler(), $context);
    }

    /**
     * A live OXID basket is duck-typed here: the handler only ever asks for
     * `getPrice()->getBruttoPrice()` and `getContents()`, and guards both with
     * `method_exists`, because the interface types the basket as `object`.
     *
     * @param array<int, array{id: string, amount: int}> $items
     */
    private function fakeBasket(float $total, array $items): object
    {
        $contents = [];
        foreach ($items as $item) {
            $contents[] = new class ($item['id'], $item['amount']) {
                public function __construct(private string $id, private int $amount)
                {
                }

                public function getProductId(): string
                {
                    return $this->id;
                }

                public function getAmount(): int
                {
                    return $this->amount;
                }
            };
        }

        return new class ($total, $contents) {
            /** @param array<int, object> $contents */
            public function __construct(private float $total, private array $contents)
            {
            }

            public function getPrice(): object
            {
                return new class ($this->total) {
                    public function __construct(private float $total)
                    {
                    }

                    public function getBruttoPrice(): float
                    {
                        return $this->total;
                    }
                };
            }

            /** @return array<int, object> */
            public function getContents(): array
            {
                return $this->contents;
            }
        };
    }

    /**
     * @param array<int, array{id: string, amount: int}> $items
     */
    private function context(float $total, array $items, string $userId = 'user_1'): PaymentContextInterface
    {
        $user = new class ($userId) {
            public function __construct(private string $id)
            {
            }

            public function getId(): string
            {
                return $this->id;
            }
        };

        $context = $this->createMock(PaymentContextInterface::class);
        $context->method('getUser')->willReturn($user);
        $context->method('getBasket')->willReturn($this->fakeBasket($total, $items));
        $context->method('getPaymentMethodId')->willReturn('oe_payments_stripe_wallet');

        return $context;
    }

    /**
     * @param array<int, array{id: string, amount: int}> $items
     */
    private function contract(ContractState $state, float $total, array $items): PaymentContractInterface
    {
        $snapshotItems = [];
        foreach ($items as $item) {
            $snapshotItems[] = ['productId' => $item['id'], 'quantity' => $item['amount']];
        }

        $contract = $this->createMock(PaymentContractInterface::class);
        $contract->method('getState')->willReturn($state);
        $contract->method('getBasketSnapshot')->willReturn(BasketSnapshot::fromArray([
            'items' => $snapshotItems,
            'totalGross' => $total,
            'totalNet' => $total,
            'totalVat' => 0.0,
            'currency' => 'EUR',
        ]));

        return $contract;
    }

    public function testShouldReuseAPendingContractForTheSameBasket(): void
    {
        $items = [['id' => 'art_1', 'amount' => 2]];
        $existing = $this->contract(ContractState::pending(), 79.0, $items);
        $this->contractRepository->method('findActiveByUserId')->willReturn($existing);

        self::assertSame($existing, $this->findReusable($this->context(79.0, $items)));
    }

    public function testShouldReuseADraftContract(): void
    {
        $items = [['id' => 'art_1', 'amount' => 1]];
        $existing = $this->contract(ContractState::draft(), 10.0, $items);
        $this->contractRepository->method('findActiveByUserId')->willReturn($existing);

        self::assertSame($existing, $this->findReusable($this->context(10.0, $items)));
    }

    public function testShouldNEVERReuseACommittedContract(): void
    {
        // The dangerous case: its payment is already done. Reusing it would
        // hang a second purchase off a paid contract.
        $items = [['id' => 'art_1', 'amount' => 1]];
        $this->contractRepository->method('findActiveByUserId')
            ->willReturn($this->contract(ContractState::committed(), 10.0, $items));

        self::assertNull($this->findReusable($this->context(10.0, $items)));
    }

    public function testShouldNotReuseAReadyToCommitContract(): void
    {
        $items = [['id' => 'art_1', 'amount' => 1]];
        $this->contractRepository->method('findActiveByUserId')
            ->willReturn($this->contract(ContractState::readyToCommit(), 10.0, $items));

        self::assertNull($this->findReusable($this->context(10.0, $items)));
    }

    public function testShouldNotReuseWhenTheQuantityChanged(): void
    {
        $this->contractRepository->method('findActiveByUserId')
            ->willReturn($this->contract(ContractState::pending(), 20.0, [['id' => 'art_1', 'amount' => 2]]));

        // Shopper went from 2 to 1: a new contract is correct, and reusing the
        // old order would charge for the wrong quantity.
        self::assertNull($this->findReusable($this->context(10.0, [['id' => 'art_1', 'amount' => 1]])));
    }

    public function testShouldNotReuseWhenAnArticleChanged(): void
    {
        $this->contractRepository->method('findActiveByUserId')
            ->willReturn($this->contract(ContractState::pending(), 10.0, [['id' => 'art_1', 'amount' => 1]]));

        self::assertNull($this->findReusable($this->context(10.0, [['id' => 'art_2', 'amount' => 1]])));
    }

    public function testShouldNotReuseWhenOnlyTheTotalChanged(): void
    {
        // Same lines, different total — a coupon or a shipping change. The order
        // was created from the old total, so it must not be reused.
        $items = [['id' => 'art_1', 'amount' => 1]];
        $this->contractRepository->method('findActiveByUserId')
            ->willReturn($this->contract(ContractState::pending(), 10.0, $items));

        self::assertNull($this->findReusable($this->context(8.5, $items)));
    }

    public function testShouldIgnoreItemOrder(): void
    {
        $stored = [['id' => 'art_2', 'amount' => 1], ['id' => 'art_1', 'amount' => 3]];
        $live = [['id' => 'art_1', 'amount' => 3], ['id' => 'art_2', 'amount' => 1]];
        $existing = $this->contract(ContractState::pending(), 42.0, $stored);
        $this->contractRepository->method('findActiveByUserId')->willReturn($existing);

        self::assertSame($existing, $this->findReusable($this->context(42.0, $live)));
    }

    public function testShouldReturnNullWhenThereIsNoActiveContract(): void
    {
        $this->contractRepository->method('findActiveByUserId')->willReturn(null);

        self::assertNull($this->findReusable($this->context(10.0, [['id' => 'art_1', 'amount' => 1]])));
    }
}
