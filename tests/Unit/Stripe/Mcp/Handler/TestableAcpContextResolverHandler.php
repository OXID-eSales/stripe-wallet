<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Mcp\Handler;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\PaymentComponent\EventSystem\Event\EventContext;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\Payments\Stripe\Mcp\Handler\AcpContextResolverHandler;

/**
 * Testable subclass that overrides OXID framework calls.
 *
 * @internal Test double only
 */
class TestableAcpContextResolverHandler extends AcpContextResolverHandler
{
    public bool $sessionBasketSet = false;
    public bool $sessionUserSet = false;

    public function __construct(
        private readonly ?User $resolveUserReturn = null,
        private readonly ?Basket $buildBasketReturn = null,
        private readonly string $sessionId = 'test_session_id'
    ) {
        parent::__construct(null);
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

        if ($this->resolveUserReturn === null || $this->buildBasketReturn === null) {
            parent::handle($event);
            return;
        }

        $user = $this->resolveUserReturn;
        $basket = $this->buildBasketReturn;

        $this->sessionBasketSet = true;
        $this->sessionUserSet = true;

        $context->set('userId', $user->getId());
        $context->set('user', $user);
        $context->set('basket', $basket);
        $context->set('sessionId', $this->sessionId);

        if (!$context->has('conditionTypes')) {
            $context->set('conditionTypes', ['payment_authorized']);
        }
    }
}
