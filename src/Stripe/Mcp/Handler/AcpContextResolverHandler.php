<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Handler;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\Country;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Eshop\Core\Registry;
use OxidEsales\PaymentComponent\Adapter\SessionAdapterInterface;
use OxidEsales\PaymentComponent\EventSystem\Handler\HandlerInterface;
use OxidEsales\PaymentComponent\Service\FileLoggerInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
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
    private const PAYMENT_ID = StripeDefinitions::STRIPE_WALLET_PAYMENT_ID;

    public function __construct(
        private readonly ?SessionAdapterInterface $sessionAdapter = null,
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
        /** @var array<string, string> $address */
        $address = $context->get('acp_fulfillment_address', []);

        $user = $this->resolveUser($buyer, $address);
        $this->validateUserId($user);

        $basket = $this->buildBasket($user, $items);

        $this->setSession($user, $basket);

        $context->set('userId', $user->getId());
        $context->set('user', $user);
        $context->set('basket', $basket);
        $context->set('sessionId', $this->getSessionId());

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
     * @param array<string, string> $address
     */
    protected function resolveUser(array $buyer, array $address = []): User
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

        return $this->createGuestUser($email, $buyer, $address);
    }

    /**
     * @param array<string, string> $buyer
     * @param array<string, string> $address
     */
    protected function createGuestUser(string $email, array $buyer, array $address = []): User
    {
        /** @var User $user */
        $user = oxNew(User::class);

        $countryId = $this->resolveCountryId($address['country'] ?? '');

        // All fields that Order::assignUserInformation() clones must be initialized,
        // otherwise OXID's magic __get returns null and `clone null` crashes.
        $userData = [
            'oxusername' => $email,
            'oxfname' => $buyer['first_name'] ?? '',
            'oxlname' => $buyer['last_name'] ?? '',
            'oxsal' => '',
            'oxcompany' => '',
            'oxactive' => 1,
            'oxstreet' => $address['line_one'] ?? '',
            'oxstreetnr' => '',
            'oxaddinfo' => '',
            'oxcity' => $address['city'] ?? '',
            'oxzip' => $address['postal_code'] ?? '',
            'oxstateid' => '',
            'oxustid' => '',
            'oxfon' => $buyer['phone_number'] ?? '',
            'oxfax' => '',
        ];

        if ($countryId !== '') {
            $userData['oxcountryid'] = $countryId;
        }

        $user->assign($userData);
        $user->save();

        $this->logEvent('AcpContextResolverHandler: Created guest user', ['userId' => $user->getId()]);

        return $user;
    }

    /**
     * Resolve country input to OXID country ID.
     *
     * Accepts ISO 3166-1 alpha-2 codes ("DE") or full country names ("Germany").
     * Tries ISO code first, then falls back to title lookup.
     */
    protected function resolveCountryId(string $countryInput): string
    {
        if ($countryInput === '') {
            return '';
        }

        /** @var Country $country */
        $country = oxNew(Country::class);

        // Try ISO alpha-2 code first (e.g., "DE", "US")
        if (strlen($countryInput) === 2) {
            $countryId = $country->getIdByCode(strtoupper($countryInput));
            if (is_string($countryId) && $countryId !== '') { // @phpstan-ignore function.alreadyNarrowedType (OXID core: getIdByCode can return null at runtime)
                return $countryId;
            }
        }

        // Fall back to title lookup (e.g., "Germany", "Deutschland")
        return $this->findCountryIdByTitle($countryInput);
    }

    /**
     * Look up OXID country ID by country title (any language).
     */
    private function findCountryIdByTitle(string $title): string
    {
        $db = \OxidEsales\Eshop\Core\DatabaseProvider::getDb();
        $countryId = $db->getOne(
            'SELECT OXID FROM oxcountry WHERE OXTITLE = ? OR OXTITLE_1 = ? OR OXTITLE_2 = ? OR OXTITLE_3 = ?',
            [$title, $title, $title, $title]
        );

        return is_string($countryId) ? $countryId : ''; // @phpstan-ignore function.alreadyNarrowedType (OXID core: getOne can return false at runtime)
    }

    private function validateUserId(User $user): void
    {
        $userId = $user->getId();
        if (!is_string($userId) || $userId === '') { // @phpstan-ignore function.alreadyNarrowedType (OXID core: getId can return null at runtime)
            throw new \RuntimeException(
                'ACP checkout failed: could not resolve or create user. '
                . 'Ensure the buyer email is valid and the database is accessible.'
            );
        }
    }

    protected function setSession(User $user, Basket $basket): void
    {
        if ($this->sessionAdapter !== null) {
            $this->sessionAdapter->setBasket($basket);
            $this->sessionAdapter->setUser($user);
            return;
        }

        $session = Registry::getSession();
        $session->setBasket($basket);
        $session->setUser($user);
    }

    protected function getSessionId(): string
    {
        if ($this->sessionAdapter !== null) {
            return $this->sessionAdapter->getSessionId();
        }

        return Registry::getSession()->getId();
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    protected function buildBasket(User $user, array $items): Basket
    {
        /** @var Basket $basket */
        $basket = oxNew(Basket::class);
        $basket->setBasketUser($user);

        $requestedCount = 0;
        foreach ($items as $item) {
            $articleId = isset($item['id']) && is_string($item['id']) ? $item['id'] : '';
            $rawQuantity = $item['quantity'] ?? 1;
            $quantity = is_numeric($rawQuantity) ? (int) $rawQuantity : 1;
            if ($articleId !== '' && $quantity > 0) {
                $basket->addToBasket($articleId, $quantity);
                $requestedCount++;
            }
        }

        if ($requestedCount === 0) {
            throw new \InvalidArgumentException('ACP checkout requires at least one valid item');
        }

        $basket->setPayment(self::PAYMENT_ID);
        $basket->calculateBasket(true);

        $actualCount = $basket->getProductsCount();
        if ($actualCount === 0) {
            throw new \RuntimeException(
                'ACP checkout failed: none of the requested items could be added to basket. '
                . 'Items may be out of stock, inactive, or require variant selection.'
            );
        }

        if ($actualCount < $requestedCount) {
            $this->logEvent('AcpContextResolverHandler: Some items were not added to basket', [
                'requested' => $requestedCount,
                'actual' => $actualCount,
            ]);
        }

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
