<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentBase\Validation\FieldValidationResult;
use OxidEsales\PaymentBase\Validation\FilesystemValidationRuleLoader;
use OxidEsales\PaymentBase\Validation\PluginPathResolverInterface;
use OxidEsales\PaymentBase\Validation\ValidationBase;
use OxidEsales\PaymentBase\Validation\ValidationBaseInterface;
use OxidEsales\Payments\Stripe\Core\StripeDefinitions;
use OxidEsales\Payments\Stripe\Service\FieldValidationFailure;
use OxidEsales\Payments\Stripe\Service\UserDataValidator;
use OxidEsales\Payments\Stripe\Service\UserDataValidatorInterface;
use OxidEsales\Payments\Stripe\Service\UserFieldReaderInterface;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 119 Phase B: unit tests for UserDataValidator facade.
 *
 * Seam choice: UserFieldReaderInterface is passed as a method argument to
 * validateForUser() so UserDataValidator can be a stateless DI singleton.
 * The production caller (Phase C controller) creates an OxidUserFieldReader
 * wrapping the live User object and passes it here.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Service\UserDataValidator::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-119')]
final class UserDataValidatorTest extends TestCase
{
    // ---------------------------------------------------------------------------
    // FieldValidationFailure VO sanity tests
    // ---------------------------------------------------------------------------

    public function testFieldValidationFailureConstructor(): void
    {
        $failure = new FieldValidationFailure(
            field: 'firstName',
            addressKind: 'billing',
            code: FieldValidationResult::CODE_BLOCKED_CHARACTER,
            offendingChar: ':',
            oxidColumn: 'oxfname',
        );

        $this->assertSame('firstName', $failure->field);
        $this->assertSame('billing', $failure->addressKind);
        $this->assertSame(FieldValidationResult::CODE_BLOCKED_CHARACTER, $failure->code);
        $this->assertSame(':', $failure->offendingChar);
        $this->assertSame('oxfname', $failure->oxidColumn);
    }

    public function testFieldValidationFailureWithNullOffendingChar(): void
    {
        $failure = new FieldValidationFailure(
            field: 'city',
            addressKind: 'delivery',
            code: FieldValidationResult::CODE_DISALLOWED_CHARACTER,
            offendingChar: null,
            oxidColumn: null,
        );

        $this->assertNull($failure->offendingChar);
        $this->assertNull($failure->oxidColumn);
    }

    // ---------------------------------------------------------------------------
    // validateForUser — billing path
    // ---------------------------------------------------------------------------

    public function testValidatesAllOxidUserColumnsByLogicalNames(): void
    {
        $validationBase = $this->createMock(ValidationBaseInterface::class);
        $validationBase->method('validateField')->willReturn(FieldValidationResult::valid());

        $reader = $this->createReaderWithFields([
            'firstName'      => "O'Connor",
            'lastName'       => 'Anne-Marie',
            'additionalInfo' => '',
            'street'         => 'Main St.',
            'houseNumber'    => '12a',
            'postalCode'     => '10115',
            'city'           => 'Köln',
            'company'        => '',
            'vatId'          => 'DE123456789',
            'phone'          => '+49 30 123-4567',
            'cellPhone'      => '',
            'personalPhone'  => '',
            'fax'            => '',
        ], deliveryFields: null);

        $failures = (new UserDataValidator($validationBase))->validateForUser($reader);

        $this->assertSame([], $failures);
    }

    public function testReportsDisallowedColonInFirstName(): void
    {
        $validationBase = $this->createMock(ValidationBaseInterface::class);
        $validationBase
            ->method('validateField')
            ->willReturnCallback(static function (string $name, mixed $value): FieldValidationResult {
                if ($name === 'firstName') {
                    return FieldValidationResult::blocked(':');
                }
                return FieldValidationResult::valid();
            });

        $reader = $this->createReaderWithFields(
            ['firstName' => 'O:Connor'],
            deliveryFields: null,
        );

        $failures = (new UserDataValidator($validationBase))->validateForUser($reader);

        $this->assertCount(1, $failures);
        $this->assertSame('firstName', $failures[0]->field);
        $this->assertSame(FieldValidationResult::CODE_BLOCKED_CHARACTER, $failures[0]->code);
        $this->assertSame(':', $failures[0]->offendingChar);
        $this->assertSame('billing', $failures[0]->addressKind);
    }

    public function testReportsTabInStreetAsControlCharacter(): void
    {
        $validationBase = $this->createMock(ValidationBaseInterface::class);
        $validationBase
            ->method('validateField')
            ->willReturnCallback(static function (string $name, mixed $value): FieldValidationResult {
                if ($name === 'street') {
                    return FieldValidationResult::controlCharacter("\t");
                }
                return FieldValidationResult::valid();
            });

        $reader = $this->createReaderWithFields(
            ['street' => "Main\tStreet"],
            deliveryFields: null,
        );

        $failures = (new UserDataValidator($validationBase))->validateForUser($reader);

        $this->assertCount(1, $failures);
        $this->assertSame('street', $failures[0]->field);
        $this->assertSame(FieldValidationResult::CODE_CONTROL_CHARACTER, $failures[0]->code);
    }

    // ---------------------------------------------------------------------------
    // validateForUser — delivery pass-through
    // ---------------------------------------------------------------------------

    public function testValidatesDeliveryAddressWhenSelected(): void
    {
        $validationBase = $this->createMock(ValidationBaseInterface::class);
        $validationBase
            ->method('validateField')
            ->willReturnCallback(static function (string $name, mixed $value): FieldValidationResult {
                if ($name === 'city' && $value === 'München!') {
                    return FieldValidationResult::disallowed('!');
                }
                return FieldValidationResult::valid();
            });

        $reader = $this->createReaderWithFields(
            billingFields: ['city' => 'Berlin'],
            deliveryFields: ['city' => 'München!'],
        );

        $failures = (new UserDataValidator($validationBase))->validateForUser($reader);

        $this->assertCount(1, $failures);
        $this->assertSame('delivery', $failures[0]->addressKind);
        $this->assertSame('city', $failures[0]->field);
    }

    public function testSkipsValidationForEmptyOptionalField(): void
    {
        $validationBase = $this->createMock(ValidationBaseInterface::class);
        // validateField must NOT be called for empty values
        $validationBase
            ->expects($this->never())
            ->method('validateField')
            ->with('company', '');

        $reader = $this->createReaderWithFields(
            ['company' => ''],
            deliveryFields: null,
        );

        $failures = (new UserDataValidator($validationBase))->validateForUser($reader);

        $this->assertSame([], $failures);
    }

    // ---------------------------------------------------------------------------
    // validateFieldMap — OPC path
    // ---------------------------------------------------------------------------

    public function testValidateFieldMapHappyPath(): void
    {
        $validationBase = $this->createMock(ValidationBaseInterface::class);
        $validationBase->method('validateField')->willReturn(FieldValidationResult::valid());

        $failures = (new UserDataValidator($validationBase))
            ->validateFieldMap(['firstName' => 'Alice'], 'billing');

        $this->assertSame([], $failures);
    }

    public function testValidateFieldMapSadPath(): void
    {
        $validationBase = $this->createMock(ValidationBaseInterface::class);
        $validationBase->method('validateField')->willReturn(FieldValidationResult::blocked(':'));

        $failures = (new UserDataValidator($validationBase))
            ->validateFieldMap(['firstName' => 'O:Connor'], 'billing');

        $this->assertCount(1, $failures);
        $this->assertSame('firstName', $failures[0]->field);
        $this->assertNull($failures[0]->oxidColumn, 'OPC path carries no OXID column mapping');
    }

    // ---------------------------------------------------------------------------
    // End-to-end integration: real ValidationBase + real rules file
    // ---------------------------------------------------------------------------

    public function testRulesFileLoadedForCorrectPluginId(): void
    {
        // __DIR__ = tests/Unit/Stripe/Service → 4 levels up = stripe module root
        $stripeRoot = dirname(__DIR__, 4);

        $pathResolver = $this->createMock(PluginPathResolverInterface::class);
        $pathResolver
            ->method('resolvePath')
            ->with(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID)
            ->willReturn($stripeRoot);

        $loader = new FilesystemValidationRuleLoader($pathResolver);
        $validationBase = new ValidationBase(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, $loader);

        $reader = $this->createReaderWithFields(
            ["firstName" => "O'Connor"],
            deliveryFields: null,
        );

        // "O'Connor" is valid per the Stripe firstName rule (allow UNICODE_LETTERS SPACES ' - .)
        $failures = (new UserDataValidator($validationBase))->validateForUser($reader);

        $this->assertSame([], $failures);
    }

    public function testRulesFileRejectsBlockedCharacterEndToEnd(): void
    {
        $stripeRoot = dirname(__DIR__, 4);

        $pathResolver = $this->createMock(PluginPathResolverInterface::class);
        $pathResolver
            ->method('resolvePath')
            ->with(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID)
            ->willReturn($stripeRoot);

        $loader = new FilesystemValidationRuleLoader($pathResolver);
        $validationBase = new ValidationBase(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, $loader);

        $reader = $this->createReaderWithFields(
            ['firstName' => 'O:Connor'],
            deliveryFields: null,
        );

        $failures = (new UserDataValidator($validationBase))->validateForUser($reader);

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
            ['captureReason' => 'Rückerstattung (Teillieferung) #2: Kunde'],
            'admin',
        );

        $this->assertSame([], $failures);
    }

    public function testCaptureReasonRejectsScriptTagAsBlockedCharacter(): void
    {
        $failures = $this->createEndToEndValidator()->validateFieldMap(
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
            ['captureReason' => "reason\u{0000}"],
            'admin',
        );

        $this->assertCount(1, $failures);
        $this->assertSame(FieldValidationResult::CODE_CONTROL_CHARACTER, $failures[0]->code);
    }

    public function testCaptureReasonEmptyValueIsValid(): void
    {
        $failures = $this->createEndToEndValidator()->validateFieldMap(
            ['captureReason' => ''],
            'admin',
        );

        $this->assertSame([], $failures, 'Reason is optional; non-empty is not this layer\'s concern');
    }

    public function testCaptureReasonFailureCarriesAdminAddressKindAndNoOxidColumn(): void
    {
        $failures = $this->createEndToEndValidator()->validateFieldMap(
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
            ['refundDescription' => 'Erstattung für Bestellung #42 (Teil 1/2)'],
            'admin',
        );

        $this->assertSame([], $failures);
    }

    public function testRefundDescriptionRejectsHtmlAsBlockedCharacter(): void
    {
        $failures = $this->createEndToEndValidator()->validateFieldMap(
            ['refundDescription' => '<img src=x>'],
            'admin',
        );

        $this->assertCount(1, $failures);
        $this->assertSame('refundDescription', $failures[0]->field);
        $this->assertSame(FieldValidationResult::CODE_BLOCKED_CHARACTER, $failures[0]->code);
        $this->assertSame('<', $failures[0]->offendingChar);
    }

    // ---------------------------------------------------------------------------
    // Implements interface
    // ---------------------------------------------------------------------------

    public function testImplementsInterface(): void
    {
        $validationBase = $this->createMock(ValidationBaseInterface::class);

        $this->assertInstanceOf(
            UserDataValidatorInterface::class,
            new UserDataValidator($validationBase)
        );
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
            new ValidationBase(StripeDefinitions::STRIPE_WALLET_PAYMENT_ID, $loader)
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
