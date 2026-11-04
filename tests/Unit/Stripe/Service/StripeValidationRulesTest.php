<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Validation\FieldValidationResult;
use OxidEsales\PaymentBase\Validation\FilesystemValidationRuleLoader;
use OxidEsales\PaymentBase\Validation\PluginPathResolverInterface;
use OxidEsales\PaymentBase\Validation\User\UserDataValidator;
use OxidEsales\PaymentBase\Validation\User\UserFieldReaderInterface;
use OxidEsales\PaymentBase\Validation\ValidationBaseFactory;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use PHPUnit\Framework\TestCase;

/**
 * What Stripe's own rules file (src/Resources/validation-rules.php) allows and blocks, run end-to-end through
 * payment-base's shared user-data validator. The validator's own behaviour is tested in payment-base.
 */
#[\PHPUnit\Framework\Attributes\Group('sprint-119')]
final class StripeValidationRulesTest extends TestCase
{
    public function testRulesFileLoadedForCorrectPluginId(): void
    {
        $reader = $this->createReaderWithFields(["firstName" => "O'Connor"], deliveryFields: null);

        // "O'Connor" is valid per the Stripe firstName rule (allow UNICODE_LETTERS SPACES ' - .)
        $failures = $this->createEndToEndValidator()
            ->validateForUser(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, $reader);

        $this->assertSame([], $failures);
    }

    public function testRulesFileRejectsBlockedCharacterEndToEnd(): void
    {
        $reader = $this->createReaderWithFields(['firstName' => 'O:Connor'], deliveryFields: null);

        $failures = $this->createEndToEndValidator()
            ->validateForUser(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, $reader);

        $this->assertCount(1, $failures);
        $this->assertSame(FieldValidationResult::CODE_BLOCKED_CHARACTER, $failures[0]->code);
        $this->assertSame(':', $failures[0]->offendingChar);
    }

    // ---------------------------------------------------------------------------
    // captureReason — Sprint 120 (admin Payment-tab capture form, STRP-129)
    // End-to-end over the real rules file: the rules entry IS the feature toggle.
    // ---------------------------------------------------------------------------

    public function testCaptureReasonAcceptsUmlautsAndAllowedLiterals(): void
    {
        $failures = $this->createEndToEndValidator()->validateFieldMap(
            StripeDefinitions::STRIPE_WALLET_PAYMENT_ID,
            ['captureReason' => 'Rückerstattung (Teillieferung) #2: Kunde'],
            'admin',
        );

        $this->assertSame([], $failures);
    }

    public function testCaptureReasonRejectsScriptTagAsBlockedCharacter(): void
    {
        $failures = $this->createEndToEndValidator()->validateFieldMap(
            StripeDefinitions::STRIPE_WALLET_PAYMENT_ID,
            ['captureReason' => '<script>alert(1)</script>'],
            'admin',
        );

        $this->assertCount(1, $failures);
        $this->assertSame('captureReason', $failures[0]->field);
        $this->assertSame(FieldValidationResult::CODE_BLOCKED_CHARACTER, $failures[0]->code);
        $this->assertSame('<', $failures[0]->offendingChar);
    }

    public function testCaptureReasonRejectsCurlyBrace(): void
    {
        $failures = $this->createEndToEndValidator()->validateFieldMap(
            StripeDefinitions::STRIPE_WALLET_PAYMENT_ID,
            ['captureReason' => 'reason {x}'],
            'admin',
        );

        $this->assertCount(1, $failures);
        $this->assertSame(FieldValidationResult::CODE_BLOCKED_CHARACTER, $failures[0]->code);
        $this->assertSame('{', $failures[0]->offendingChar);
    }

    public function testCaptureReasonRejectsControlCharacter(): void
    {
        $failures = $this->createEndToEndValidator()->validateFieldMap(
            StripeDefinitions::STRIPE_WALLET_PAYMENT_ID,
            ['captureReason' => "reason\u{0000}"],
            'admin',
        );

        $this->assertCount(1, $failures);
        $this->assertSame(FieldValidationResult::CODE_CONTROL_CHARACTER, $failures[0]->code);
    }

    public function testCaptureReasonEmptyValueIsValid(): void
    {
        $failures = $this->createEndToEndValidator()->validateFieldMap(
            StripeDefinitions::STRIPE_WALLET_PAYMENT_ID,
            ['captureReason' => ''],
            'admin',
        );

        $this->assertSame([], $failures, 'Reason is optional; non-empty is not this layer\'s concern');
    }

    public function testCaptureReasonFailureCarriesAdminAddressKindAndNoOxidColumn(): void
    {
        $failures = $this->createEndToEndValidator()->validateFieldMap(
            StripeDefinitions::STRIPE_WALLET_PAYMENT_ID,
            ['captureReason' => 'bad|pipe'],
            'admin',
        );

        $this->assertCount(1, $failures);
        $this->assertSame('admin', $failures[0]->addressKind);
        $this->assertNull($failures[0]->oxidColumn);
    }

    // ---------------------------------------------------------------------------
    // refundDescription — Sprint 121 (admin Payment-tab refund path, STRP-129)
    // ---------------------------------------------------------------------------

    public function testRefundDescriptionAcceptsUmlautsAndAllowedLiterals(): void
    {
        $failures = $this->createEndToEndValidator()->validateFieldMap(
            StripeDefinitions::STRIPE_WALLET_PAYMENT_ID,
            ['refundDescription' => 'Erstattung für Bestellung #42 (Teil 1/2)'],
            'admin',
        );

        $this->assertSame([], $failures);
    }

    public function testRefundDescriptionRejectsHtmlAsBlockedCharacter(): void
    {
        $failures = $this->createEndToEndValidator()->validateFieldMap(
            StripeDefinitions::STRIPE_WALLET_PAYMENT_ID,
            ['refundDescription' => '<img src=x>'],
            'admin',
        );

        $this->assertCount(1, $failures);
        $this->assertSame('refundDescription', $failures[0]->field);
        $this->assertSame(FieldValidationResult::CODE_BLOCKED_CHARACTER, $failures[0]->code);
        $this->assertSame('<', $failures[0]->offendingChar);
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    /**
     * Real ValidationBase + real FilesystemValidationRuleLoader over the real
     * src/Resources/validation-rules.php — the Sprint 119 end-to-end pattern.
     */
    private function createEndToEndValidator(): UserDataValidator
    {
        // __DIR__ = tests/Unit/Stripe/Service → 4 levels up = stripe module root
        $stripeRoot = dirname(__DIR__, 4);

        $pathResolver = $this->createMock(PluginPathResolverInterface::class);
        $pathResolver
            ->method('resolvePath')
            ->with(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID)
            ->willReturn($stripeRoot);

        $loader = new FilesystemValidationRuleLoader($pathResolver);

        return new UserDataValidator(
            new ValidationBaseFactory($loader)  // Creates real ValidationBase for Stripe
        );
    }

    /**
     * @param array<string, string> $billingFields
     * @param array<string, string>|null $deliveryFields  null = no delivery address selected
     */
    private function createReaderWithFields(
        array $billingFields,
        ?array $deliveryFields,
    ): UserFieldReaderInterface {
        $reader = $this->createMock(UserFieldReaderInterface::class);

        $reader->method('readBillingField')
            ->willReturnCallback(static function (string $logicalName) use ($billingFields): string {
                return $billingFields[$logicalName] ?? '';
            });

        if ($deliveryFields !== null) {
            $reader->method('hasDeliveryAddress')->willReturn(true);
            $reader->method('readDeliveryField')
                ->willReturnCallback(static function (string $logicalName) use ($deliveryFields): string {
                    return $deliveryFields[$logicalName] ?? '';
                });
        } else {
            $reader->method('hasDeliveryAddress')->willReturn(false);
        }

        return $reader;
    }
}
