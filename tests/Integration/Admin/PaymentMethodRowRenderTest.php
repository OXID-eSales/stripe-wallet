<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Admin;

use OxidEsales\EshopCommunity\Internal\Container\ContainerFactory;
use OxidEsales\EshopCommunity\Internal\Framework\Templating\TemplateRendererBridgeInterface;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 136 — the "payment method used" row must actually reach the page.
 *
 * The unit tests pin the view-data the builder produces; this pins the other
 * half, which no unit test can see: that the template consumes that shape
 * without a Twig error and renders each of the three states an operator will
 * meet. A view-data key renamed on one side of that boundary fails here.
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('sprint-136')]
#[\PHPUnit\Framework\Attributes\Group('requires-oxid-container')]
final class PaymentMethodRowRenderTest extends TestCase
{
    private const TEMPLATE = '@oe_payments_stripe_wallet/admin/panel/stripe_panel.html.twig';

    public function testWalletPaymentRendersTheWalletAndTheDemotedCard(): void
    {
        $cell = $this->renderRow([
            'isKnown' => true,
            'label'   => 'Apple Pay',
            'detail'  => 'Mastercard •••• 0007',
            'raw'     => 'card',
        ]);

        self::assertStringContainsString('Apple Pay', $cell);
        self::assertStringContainsString('Mastercard', $cell);
        self::assertStringContainsString('0007', $cell);
    }

    public function testMethodWithoutCardDetailRendersTheLabelAlone(): void
    {
        $cell = $this->renderRow([
            'isKnown' => true,
            'label'   => 'Klarna',
            'detail'  => null,
            'raw'     => 'klarna',
        ]);

        self::assertStringContainsString('Klarna', $cell);
        self::assertStringNotContainsString('••••', $cell);
    }

    public function testUnknownMethodRendersADash(): void
    {
        $cell = $this->renderRow([
            'isKnown' => false,
            'label'   => '',
            'detail'  => null,
            'raw'     => null,
        ]);

        self::assertStringContainsString('ndash', $cell);
    }

    /**
     * @param array{isKnown: bool, label: string, detail: ?string, raw: ?string} $paymentMethod
     */
    private function renderRow(array $paymentMethod): string
    {
        $html = $this->render($paymentMethod);

        self::assertMatchesRegularExpression(
            '#data-testid="payment-method-used"#',
            $html,
            'The payment-method row did not render at all.'
        );

        preg_match('#data-testid="payment-method-used"[^>]*>(.*?)</td>#s', $html, $matches);

        return $matches[1] ?? '';
    }

    /**
     * @param array{isKnown: bool, label: string, detail: ?string, raw: ?string} $paymentMethod
     */
    private function render(array $paymentMethod): string
    {
        $renderer = ContainerFactory::getInstance()->getContainer()
            ->get(TemplateRendererBridgeInterface::class)
            ->getTemplateRenderer();

        return $renderer->renderTemplate(self::TEMPLATE, $this->viewData() + ['paymentMethod' => $paymentMethod]);
    }

    /**
     * The rest of the panel's view-data, in the shape
     * {@see \OxidEsales\Payments\Stripe\Admin\StripePanelViewDataBuilder} emits.
     *
     * @return array<string, mixed>
     */
    private function viewData(): array
    {
        return [
            'orderId' => 'oid1',
            'orderNumber' => '4711',
            'contractId' => 'c1',
            'paymentType' => 'oscstripe',
            'transactionId' => 'pi_1',
            'externalTransId' => '',
            'currency' => 'EUR',
            'amount' => '100.00',
            'capturedAmount' => '100.00',
            'refundedAmount' => '0.00',
            'hasRefunds' => false,
            'capturableAmount' => '0.00',
            'capturableRaw' => 0.0,
            'remainingRefundable' => '100.00',
            'remainingRefundableRaw' => 100.0,
            'isCapturable' => false,
            'isRefundable' => true,
            'isCancellable' => false,
            'dashboardPrefix' => '/test',
            'isTestMode' => true,
            'hasApiError' => false,
            'apiError' => null,
            'transactions' => [],
            'validationErrors' => [],
        ];
    }
}
