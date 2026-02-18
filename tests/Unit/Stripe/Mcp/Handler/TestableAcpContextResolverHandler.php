<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Handler;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\PaymentComponent\Adapter\SessionAdapterInterface;
use OxidEsales\Payments\Stripe\Mcp\Handler\AcpContextResolverHandler;

/**
 * Testable subclass that overrides OXID framework calls.
 *
 * Overrides: resolveUser(), resolveCountryId(), buildBasket()
 * These methods depend on oxNew(), Registry, and database — impossible to call in unit tests.
 *
 * Session operations are handled by the injected SessionAdapterInterface mock.
 *
 * @internal Test double only
 */
class TestableAcpContextResolverHandler extends AcpContextResolverHandler
{
    public bool $sessionSet = false;

    /** @var array<string, string> */
    public array $lastResolvedAddress = [];

    /** @var array<string, string> */
    public array $lastResolvedBuyer = [];

    public function __construct(
        private readonly ?User $resolveUserReturn = null,
        private readonly ?Basket $buildBasketReturn = null,
        private readonly string $sessionId = 'test_session_id',
        private readonly string $countryId = 'test_country_id'
    ) {
        $sessionAdapter = new class ($sessionId) implements SessionAdapterInterface {
            private bool $basketSet = false;
            private bool $userSet = false;

            public function __construct(private readonly string $sessionId)
            {
            }

            public function getSessionId(): string
            {
                return $this->sessionId;
            }

            public function getBasket(): ?object
            {
                return null;
            }

            public function setVariable(string $name, mixed $value): void
            {
            }

            public function getVariable(string $name): mixed
            {
                return null;
            }

            public function setBasket(object $basket): void
            {
                $this->basketSet = true;
            }

            public function setUser(object $user): void
            {
                $this->userSet = true;
            }

            public function wasSessionSet(): bool
            {
                return $this->basketSet && $this->userSet;
            }
        };

        parent::__construct($sessionAdapter, null);
    }

    protected function resolveUser(array $buyer, array $address = []): User
    {
        $this->lastResolvedBuyer = $buyer;
        $this->lastResolvedAddress = $address;

        if ($this->resolveUserReturn !== null) {
            return $this->resolveUserReturn;
        }

        // Fall through to real implementation — will fail in unit test context
        return parent::resolveUser($buyer, $address);
    }

    protected function resolveCountryId(string $countryInput): string
    {
        if ($countryInput === '') {
            return '';
        }

        return $this->countryId;
    }

    protected function buildBasket(User $user, array $items): Basket
    {
        if ($this->buildBasketReturn !== null) {
            return $this->buildBasketReturn;
        }

        return parent::buildBasket($user, $items);
    }

    protected function setSession(User $user, Basket $basket): void
    {
        parent::setSession($user, $basket);
        $this->sessionSet = true;
    }
}
