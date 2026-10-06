<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Controller;

use OxidEsales\GraphQL\Base\Service\Authentication;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessStartRequest;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutCancelResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutReturnResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutStartResult;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\GraphQL\Exception\StripeCheckoutError;
use RuntimeException;
use TheCodingMachine\GraphQLite\Annotations\Logged;
use TheCodingMachine\GraphQLite\Annotations\Mutation;
use TheCodingMachine\GraphQLite\Annotations\Right;
use TheCodingMachine\GraphQLite\Types\ID;

/**
 * The Stripe checkout for the GraphQL Storefront (GRAPH-QL / PS4, Option B):
 * core `placeOrder` is not used for Stripe baskets; these three mutations are.
 *
 *   basketSetPayment(basketId, "oe_payments_stripe_wallet")
 *   stripeCheckoutStart(basketId, confirmTermsAndConditions, returnUrl, cancelUrl, uiMode)
 *       → { contractId, contractToken, orderNumber, redirectUrl | clientSecret, renderMode }
 *   … shopper pays; Stripe sends them to returnUrl?session_id=cs_… …
 *   stripeCheckoutReturn(contractId, contractToken, checkoutSessionId) → { status, orderId, orderNumber, contractState }
 *   stripeCheckoutCancel(contractId, contractToken) → { cancelled, contractId, contractState }
 *
 * Everything provider-neutral lives in payment-base's HeadlessCheckoutService;
 * this class adds the JWT user, Stripe's session id and a Stripe contract
 * token for the return resolver (payment-base already verified its own token),
 * and turns refusals into client-safe errors.
 *
 * @since 3.4.0
 */
final class StripeCheckout
{
    public function __construct(
        private readonly HeadlessCheckoutServiceInterface $checkout,
        private readonly TokenServiceInterface $tokenService,
        private readonly ?Authentication $authentication = null,
    ) {
    }

    #[Mutation]
    #[Logged]
    #[Right('PAYMENT_CHECKOUT')]
    public function stripeCheckoutStart(
        ID $basketId,
        bool $confirmTermsAndConditions,
        string $returnUrl,
        ?string $cancelUrl = null,
        string $uiMode = 'hosted'
    ): CheckoutStartResult {
        try {
            return new CheckoutStartResult($this->checkout->start(new HeadlessStartRequest(
                userId: $this->userId(),
                basketId: (string) $basketId,
                confirmTermsAndConditions: $confirmTermsAndConditions,
                returnUrl: $returnUrl,
                cancelUrl: $cancelUrl,
                uiMode: $uiMode,
            )));
        } catch (HeadlessCheckoutException $e) {
            throw new StripeCheckoutError($e);
        }
    }

    /**
     * @param string|null $checkoutSessionId the `session_id` Stripe appended to the return URL
     */
    #[Mutation]
    #[Logged]
    #[Right('PAYMENT_CHECKOUT')]
    public function stripeCheckoutReturn(
        string $contractId,
        string $contractToken,
        ?string $checkoutSessionId = null
    ): CheckoutReturnResult {
        $providerParams = ['contract_token' => $this->tokenService->generateToken($contractId)];
        if ($checkoutSessionId !== null && $checkoutSessionId !== '') {
            $providerParams = ['checkoutSessionId' => $checkoutSessionId] + $providerParams;
        }

        try {
            return new CheckoutReturnResult($this->checkout->return($contractId, $contractToken, $providerParams));
        } catch (HeadlessCheckoutException $e) {
            throw new StripeCheckoutError($e);
        }
    }

    #[Mutation]
    #[Logged]
    #[Right('PAYMENT_CHECKOUT')]
    public function stripeCheckoutCancel(string $contractId, string $contractToken): CheckoutCancelResult
    {
        try {
            return new CheckoutCancelResult($this->checkout->cancel($contractId, $contractToken));
        } catch (HeadlessCheckoutException $e) {
            throw new StripeCheckoutError($e);
        }
    }

    private function userId(): string
    {
        if ($this->authentication === null) {
            throw new RuntimeException('graphql-base is not installed; the Stripe checkout mutations need it');
        }

        return (string) $this->authentication->getUser()->id();
    }
}
