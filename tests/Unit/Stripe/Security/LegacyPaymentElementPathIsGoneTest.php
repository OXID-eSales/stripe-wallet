<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Security;

use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;
use OxidEsales\Payments\Stripe\Controller\StripeOrderController;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * VR-20 R2 (S-1): the legacy Payment Element path took the PaymentIntent id from the request
 * (`payment_intent`) and authorized the session's contract with it — nothing tied that
 * PaymentIntent to the contract, so one paid PaymentIntent could commit other orders. The
 * Checkout Session return (`checkoutSuccess`, guarded by the contract token and the owner)
 * and the webhook are the only ways a Stripe payment commits. These assertions keep the old
 * entry points from coming back.
 */
final class LegacyPaymentElementPathIsGoneTest extends TestCase
{
    /**
     * @return array<string, array{class-string, string}>
     */
    public static function removedEntryPoints(): array
    {
        return [
            'route executeStripePayment' => [StripeOrderController::class, 'executeStripePayment'],
            'route stripeReturn' => [StripeOrderController::class, 'stripeReturn'],
            'PaymentIntent id from the request' => [ControllerRequestHelper::class, 'getPaymentIntentIdFromRequest'],
            'redirect_status from the request' => [ControllerRequestHelper::class, 'getRedirectStatusFromRequest'],
        ];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('removedEntryPoints')]
    public function testEntryPointIsGone(string $class, string $method): void
    {
        self::assertFalse(method_exists($class, $method), $class . '::' . $method . ' must not exist');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function removedClasses(): array
    {
        return [
            'execute event' => ['OxidEsales\\Payments\\Stripe\\EventSystem\\Event\\StripePaymentExecuteEvent'],
            'return event' => ['OxidEsales\\Payments\\Stripe\\EventSystem\\Event\\StripePaymentReturnEvent'],
            'status handler' => ['OxidEsales\\Payments\\Stripe\\EventSystem\\Handler\\StripePaymentStatusHandler'],
            'return handler' => ['OxidEsales\\Payments\\Stripe\\EventSystem\\Handler\\StripePaymentReturnHandler'],
        ];
    }

    #[DataProvider('removedClasses')]
    public function testHandlerChainIsGone(string $class): void
    {
        self::assertFalse(class_exists($class), $class . ' must not exist');
    }
}
