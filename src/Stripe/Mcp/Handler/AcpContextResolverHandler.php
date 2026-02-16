<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Handler;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;

/**
 * Resolves ACP context data into OXID objects for downstream handlers.
 *
 * ACP checkout requests carry buyer/items as plain arrays. The contract
 * creation chain (ContractCreationHandler) requires a userId string and
 * an OXID Basket object. This handler bridges that gap by:
 *
 * 1. Resolving (or creating) an OXID User from the ACP buyer email
 * 2. Building an OXID Basket from the ACP item list
 * 3. Setting both on the EventContext and OXID session
 *
 * Priority 200 — runs BEFORE StripeContractCreationHandler (100).
 * Guard: only activates when context source === 'acp'.
 */
class AcpContextResolverHandler implements HandlerInterface
{
    private const PAYMENT_ID = 'oxidstripe';

    public function __construct(
        private readonly ?FileLoggerInterface $eventLogger = null
    ) {
    }

    public static function getHandledEventClass(): string
    {
        return StripeCheckoutSessionRequestEvent::class;
    }

    public function getPriority(): int
    {
        return 200;
    }

    public function handle(object $event): void
    {
        if (!$event instanceof StripeCheckoutSessionRequestEvent) {
            return;
        }

        $context = $event->getContext();

        if ($context->get('source') !== 'acp') {
            return;
        }

        if (is_string($context->get('userId')) && $context->get('userId') !== '') {
            return;
        }

        $this->logEvent('AcpContextResolverHandler: Resolving ACP context');

        /** @var array<string, string> $buyer */
        $buyer = $context->get('acp_buyer', []);
        /** @var list<array<string, mixed>> $items */
        $items = $context->get('acp_items', []);

        $user = $this->resolveUser($buyer);
        $basket = $this->buildBasket($user, $items);

        $session = Registry::getSession();
        $session->setBasket($basket);
        $session->setUser($user);

        $context->set('userId', $user->getId());
        $context->set('user', $user);
        $context->set('basket', $basket);
        $context->set('sessionId', $session->getId());

        if (!$context->has('conditionTypes')) {
            $context->set('conditionTypes', ['payment_authorized']);
        }

        $this->logEvent('AcpContextResolverHandler: Context resolved', [
            'userId' => $user->getId(),
            'basketItemCount' => $basket->getProductsCount(),
        ]);
    }

    /**
     * @param array<string, string> $buyer
     */
    protected function resolveUser(array $buyer): User
    {
        $email = $buyer['email'] ?? '';
        if ($email === '') {
            throw new \InvalidArgumentException('ACP buyer email is required');
        }

        /** @var User $user */
        $user = oxNew(User::class);

        $existingId = $user->getIdByUserName($email);
        if (is_string($existingId) && $existingId !== '') {
            $user->load($existingId);
            $this->logEvent('AcpContextResolverHandler: Loaded existing user', ['userId' => $existingId]);
            return $user;
        }

        return $this->createGuestUser($email, $buyer);
    }

    /**
     * @param array<string, string> $buyer
     */
    protected function createGuestUser(string $email, array $buyer): User
    {
        /** @var User $user */
        $user = oxNew(User::class);

        $user->assign([
            'oxusername' => $email,
            'oxfname' => $buyer['first_name'] ?? '',
            'oxlname' => $buyer['last_name'] ?? '',
            'oxactive' => 1,
        ]);

        $user->save();

        $this->logEvent('AcpContextResolverHandler: Created guest user', ['userId' => $user->getId()]);

        return $user;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    protected function buildBasket(User $user, array $items): Basket
    {
        /** @var Basket $basket */
        $basket = oxNew(Basket::class);
        $basket->setBasketUser($user);

        foreach ($items as $item) {
            $articleId = isset($item['id']) && is_string($item['id']) ? $item['id'] : '';
            $rawQuantity = $item['quantity'] ?? 1;
            $quantity = is_numeric($rawQuantity) ? (int) $rawQuantity : 1;
            if ($articleId !== '' && $quantity > 0) {
                $basket->addToBasket($articleId, $quantity);
            }
        }

        $basket->setPayment(self::PAYMENT_ID);
        $basket->calculateBasket(true);

        return $basket;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logEvent(string $message, array $context = []): void
    {
        if ($this->eventLogger !== null) {
            $this->eventLogger->log($message, $context);
        }
    }
}
