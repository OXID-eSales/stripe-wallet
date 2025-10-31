<?php

declare(strict_types=1);

namespace OxidSolutionCatalysts\Payments\Component\Contract;

use OxidSolutionCatalysts\Payments\Component\Model\AbstractModel;

class PaymentContract extends AbstractModel implements PaymentContractInterface
{
    private int $shopId;
    private string $userId;
    private ?string $orderId = null;
    private ContractState $state;
    private BasketSnapshot $basketSnapshot;

    /**
     * @var array<int, ContractCondition>
     */
    private array $conditions = [];
    private ?\DateTimeInterface $expiresAt = null;
    private \DateTimeInterface $createdAt;
    private \DateTimeInterface $updatedAt;
    private ?\DateTimeInterface $fulfilledAt = null;

    private ?string $provider = null;
    private ?string $providerOrderId = null;
    private ?string $providerRedirectUrl = null;

    public function __construct(
        int $shopId,
        string $userId,
        BasketSnapshot $basketSnapshot,
        ?string $id = null
    ) {
        $this->id = $id ?? $this->generateId('contract');
        $this->shopId = $shopId;
        $this->userId = $userId;
        $this->basketSnapshot = $basketSnapshot;
        $this->state = ContractState::draft();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        $this->expiresAt = (new \DateTime())->add(new \DateInterval('PT24H'));
    }

    public function addCondition(ContractCondition $condition): void
    {
        if (!$this->state->isDraft()) {
            throw new \DomainException('Cannot add conditions after DRAFT state');
        }

        $this->conditions[] = $condition;
        $this->touch();
    }

    public function transitionToPending(): void
    {
        if (!$this->state->isDraft()) {
            throw new \DomainException('Can only transition to PENDING from DRAFT state');
        }

        if (empty($this->conditions)) {
            throw new \DomainException('Cannot transition to PENDING without conditions');
        }

        $this->state = ContractState::pending();
        $this->touch();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function fulfillCondition(string $type, array $data = []): void
    {
        $condition = $this->findCondition($type);

        if ($condition === null) {
            throw new \DomainException("Condition type '{$type}' not found");
        }

        $condition->fulfill($data);
        $this->touch();

        if ($this->areAllConditionsFulfilled() && $this->state->isPending()) {
            $this->state = ContractState::readyToCommit();
        }
    }

    public function failCondition(string $type, string $reason): void
    {
        $condition = $this->findCondition($type);

        if ($condition === null) {
            throw new \DomainException("Condition type '{$type}' not found");
        }

        $condition->fail($reason);
        $this->fail("Condition '{$type}' failed: {$reason}");
        $this->touch();
    }

    public function areAllConditionsFulfilled(): bool
    {
        if (empty($this->conditions)) {
            return false;
        }

        foreach ($this->conditions as $condition) {
            if (!$condition->isFulfilled()) {
                return false;
            }
        }

        return true;
    }

    public function commitToOrder(string $orderId): void
    {
        if (!$this->state->isReadyToCommit()) {
            throw new \DomainException('Contract must be in READY_TO_COMMIT state to commit');
        }

        if (!$this->areAllConditionsFulfilled()) {
            throw new \DomainException('Cannot commit contract with unfulfilled conditions');
        }

        $this->orderId = $orderId;
        $this->state = ContractState::committed();
        $this->touch();
    }

    public function fulfill(): void
    {
        if (!$this->state->isCommitted()) {
            throw new \DomainException('Contract must be COMMITTED before fulfillment');
        }

        $this->state = ContractState::fulfilled();
        $this->fulfilledAt = new \DateTime();
        $this->touch();
    }

    public function cancel(string $reason = ''): void
    {
        if ($this->state->isTerminal()) {
            throw new \DomainException('Cannot cancel a terminal state contract');
        }

        $this->state = ContractState::cancelled();
        $this->touch();
    }

    public function fail(string $reason): void
    {
        if ($this->state->isTerminal()) {
            throw new \DomainException('Cannot fail a terminal state contract');
        }

        $this->state = ContractState::failed();
        $this->touch();
    }

    public function expire(): void
    {
        if ($this->state->isTerminal()) {
            throw new \DomainException('Cannot expire a terminal state contract');
        }

        $this->state = ContractState::expired();
        $this->touch();
    }

    public function setProvider(string $provider, string $providerOrderId, ?string $redirectUrl = null): void
    {
        $this->provider = $provider;
        $this->providerOrderId = $providerOrderId;
        $this->providerRedirectUrl = $redirectUrl;
        $this->touch();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getShopId(): int
    {
        return $this->shopId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getOrderId(): ?string
    {
        return $this->orderId;
    }

    public function getState(): ContractState
    {
        return $this->state;
    }

    public function getStateValue(): string
    {
        return $this->state->getValue();
    }

    public function getBasketSnapshot(): BasketSnapshot
    {
        return $this->basketSnapshot;
    }

    /**
     * @return array<int, ContractCondition>
     */
    public function getConditions(): array
    {
        return $this->conditions;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getProviderOrderId(): ?string
    {
        return $this->providerOrderId;
    }

    public function getProviderRedirectUrl(): ?string
    {
        return $this->providerRedirectUrl;
    }

    public function getExpiresAt(): ?\DateTimeInterface
    {
        return $this->expiresAt;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function getFulfilledAt(): ?\DateTimeInterface
    {
        return $this->fulfilledAt;
    }

    public function isExpired(): bool
    {
        if ($this->state->isTerminal()) {
            return false;
        }

        return $this->expiresAt !== null && $this->expiresAt < new \DateTime();
    }

    public function isInState(string $state): bool
    {
        return $this->state->getValue() === $state;
    }

    public function getAmount(): float
    {
        return $this->basketSnapshot->getTotalGross();
    }

    public function getCurrency(): string
    {
        return $this->basketSnapshot->getCurrency();
    }

    private function findCondition(string $type): ?ContractCondition
    {
        foreach ($this->conditions as $condition) {
            if ($condition->getType() === $type) {
                return $condition;
            }
        }

        return null;
    }

    private function touch(): void
    {
        $this->updatedAt = new \DateTime();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shopId' => $this->shopId,
            'userId' => $this->userId,
            'orderId' => $this->orderId,
            'state' => $this->state->getValue(),
            'basketSnapshot' => $this->basketSnapshot->toArray(),
            'conditions' => array_map(fn($c) => $c->toArray(), $this->conditions),
            'provider' => $this->provider,
            'providerOrderId' => $this->providerOrderId,
            'providerRedirectUrl' => $this->providerRedirectUrl,
            'expiresAt' => $this->expiresAt?->format('Y-m-d H:i:s'),
            'createdAt' => $this->createdAt->format('Y-m-d H:i:s'),
            'updatedAt' => $this->updatedAt->format('Y-m-d H:i:s'),
            'fulfilledAt' => $this->fulfilledAt?->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $contract = new self(
            shopId: $data['shopId'],
            userId: $data['userId'],
            basketSnapshot: BasketSnapshot::fromArray($data['basketSnapshot']),
            id: $data['id']
        );

        $contract->orderId = $data['orderId'] ?? null;
        $contract->state = ContractState::fromValue($data['state']);
        $contract->provider = $data['provider'] ?? null;
        $contract->providerOrderId = $data['providerOrderId'] ?? null;
        $contract->providerRedirectUrl = $data['providerRedirectUrl'] ?? null;

        if (isset($data['conditions'])) {
            $contract->conditions = array_map(
                fn($c) => ContractCondition::fromArray($c),
                $data['conditions']
            );
        }

        if (isset($data['expiresAt'])) {
            $contract->expiresAt = new \DateTime($data['expiresAt']);
        }

        $contract->createdAt = isset($data['createdAt'])
            ? new \DateTime($data['createdAt'])
            : new \DateTime();

        $contract->updatedAt = isset($data['updatedAt'])
            ? new \DateTime($data['updatedAt'])
            : new \DateTime();

        if (isset($data['fulfilledAt'])) {
            $contract->fulfilledAt = new \DateTime($data['fulfilledAt']);
        }

        return $contract;
    }
}
