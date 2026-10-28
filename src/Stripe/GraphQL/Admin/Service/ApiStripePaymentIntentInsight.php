<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\GraphQL\Admin\Service;

use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use Throwable;

/**
 * The PaymentIntent facts from the Stripe API through the module's adapter (the one the classic panel uses), once per
 * intent and request. A failure is reported as a short message with every key masked, never as a payload.
 */
final class ApiStripePaymentIntentInsight implements StripePaymentIntentInsightInterface
{
    private const MAX_MESSAGE_LENGTH = 200;

    /** @var array<string, PaymentIntentFacts> */
    private array $facts = [];

    public function __construct(private readonly StripeAdapterFactoryInterface $adapterFactory)
    {
    }

    public function factsOf(string $paymentIntentId): PaymentIntentFacts
    {
        return $this->facts[$paymentIntentId] ??= $this->ask($paymentIntentId);
    }

    private function ask(string $paymentIntentId): PaymentIntentFacts
    {
        try {
            $intent = $this->adapterFactory->getStripeAdapter()->retrievePaymentIntent($paymentIntentId);
        } catch (Throwable $failure) {
            return PaymentIntentFacts::unavailable($this->message($failure));
        }

        return new PaymentIntentFacts($intent->status, $intent->latestChargeId, null);
    }

    private function message(Throwable $failure): string
    {
        // Stripe's messages may quote a masked key, and the SDK may append the request; keep the first line only.
        $firstLine = strtok($failure->getMessage(), "\r\n");

        $redacted = StripeSecretRedactor::redact($firstLine === false ? '' : $firstLine);

        return mb_substr($redacted, 0, self::MAX_MESSAGE_LENGTH);
    }
}
