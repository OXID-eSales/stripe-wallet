<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\PaymentBase\Validation\Message\MessageFormatterInterface;
use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;
use OxidEsales\Payments\Stripe\Controller\StripeOrderController;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidatorInterface;
use OxidEsales\Payments\Stripe\Service\FieldValidationFailure;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use OxidEsales\Payments\Stripe\Service\UserDataValidationMessageFormatter;
use OxidEsales\Payments\Stripe\Service\UserDataValidatorInterface;
use OxidEsales\Payments\Stripe\Service\UserFieldReaderInterface;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 119 Phase C (STRP-129): unit tests for UserDataValidator wiring
 * inside StripeOrderController::createCheckoutSession().
 *
 * Tests the pre-dispatch gate that rejects invalid user data with HTTP 422
 * before any contract / order creation.
 *
 * Uses the testable-subclass pattern; the controller under test is the real
 * StripeOrderController with seams overridden — not a copy.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\StripeOrderController::class)]
#[\PHPUnit\Framework\Attributes\Group('sprint-119')]
#[\PHPUnit\Framework\Attributes\Group('user-data-validation')]
final class StripeOrderControllerValidationTest extends TestCase
{
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private RetryCleanupService&MockObject $cleanupService;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->cleanupService  = $this->createMock(RetryCleanupService::class);
    }

    // ==========================================
    // T1 — Invalid user data → HTTP 422; event NOT dispatched
    // ==========================================
    #[\PHPUnit\Framework\Attributes\Test]
    public function checkoutDispatchBlockedOnInvalidUserData(): void
    {
        $failure = new FieldValidationFailure(
            field: 'firstName',
            addressKind: 'billing',
            code: 'blocked_character',
            offendingChar: ':',
            oxidColumn: 'oxfname',
        );
        $validator = $this->stubValidatorReturning([$failure]);

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $controller = $this->createController($validator);

        ob_start();
        $controller->createCheckoutSession();
        $output = (string) ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertFalse($json['valid']);
        $this->assertIsArray($json['errors']);
        $this->assertCount(1, $json['errors']);
        $this->assertSame('firstName', $json['errors'][0]['field']);
        $this->assertSame('blocked_character', $json['errors'][0]['code']);
        $this->assertSame(':', $json['errors'][0]['char']);
        $this->assertSame(422, $controller->getLastHttpStatusCode());
    }

    // ==========================================
    // T2 — Valid user data → dispatcher IS called (happy-path regression guard)
    // ==========================================
    #[\PHPUnit\Framework\Attributes\Test]
    public function validUserPassesThroughToDispatch(): void
    {
        $validator = $this->stubValidatorReturning([]);

        $this->eventDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->isInstanceOf(StripeCheckoutSessionRequestEvent::class))
            ->willReturnCallback(function ($event) {
                $event->getContext()->set('checkoutSessionId', 'cs_test_ok');
                $event->getContext()->set('checkoutUrl', 'https://checkout.stripe.com/pay/cs_test_ok');
                $event->getContext()->set('contractId', 'contract_ok');
                return $event;
            });

        $controller = $this->createController($validator);

        ob_start();
        $controller->createCheckoutSession();
        ob_get_clean();

        $this->assertSame(200, $controller->getLastHttpStatusCode());
    }

    // ==========================================
    // T3 — Validation runs AFTER preconditions (basket empty → 500 wins over 422)
    // ==========================================
    #[\PHPUnit\Framework\Attributes\Test]
    public function validationRunsAfterPreconditionCheck(): void
    {
        // Validator would return a failure — but basket is empty so preconditions
        // throw first; the 500 from the try-catch wins over 422.
        $failure = new FieldValidationFailure(
            field: 'street',
            addressKind: 'billing',
            code: 'disallowed_character',
            offendingChar: '<',
            oxidColumn: 'oxstreet',
        );
        $validator = $this->stubValidatorReturning([$failure]);

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $controller = $this->createController($validator, hasEmptyBasket: true);

        ob_start();
        $controller->createCheckoutSession();
        ob_get_clean();

        // Precondition failure (basket empty) gives 500, not 422
        $this->assertSame(500, $controller->getLastHttpStatusCode());
    }

    // ==========================================
    // T4 — Validation failure does NOT create contract (StripeCheckoutSessionRequestEvent
    //      not dispatched — covers OrderCreatedEvent which fires inside that handler)
    // ==========================================
    #[\PHPUnit\Framework\Attributes\Test]
    public function validationFailureDoesNotDispatchCheckoutSessionEvent(): void
    {
        $failures = [
            new FieldValidationFailure(
                field: 'city',
                addressKind: 'delivery',
                code: 'disallowed_character',
                offendingChar: '!',
                oxidColumn: 'oxcity',
            ),
            new FieldValidationFailure(
                field: 'lastName',
                addressKind: 'billing',
                code: 'blocked_character',
                offendingChar: ';',
                oxidColumn: 'oxlname',
            ),
        ];
        $validator = $this->stubValidatorReturning($failures);

        $this->eventDispatcher
            ->expects($this->never())
            ->method('dispatch');

        $controller = $this->createController($validator);

        ob_start();
        $controller->createCheckoutSession();
        $output = (string) ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertFalse($json['valid']);
        $this->assertCount(2, $json['errors']);
    }

    // ==========================================
    // T5 — JSON shape: addressKind and all required fields are present
    // ==========================================
    #[\PHPUnit\Framework\Attributes\Test]
    public function emittedJsonContainsAddressKindAndAllRequiredFields(): void
    {
        $failure = new FieldValidationFailure(
            field: 'street',
            addressKind: 'delivery',
            code: 'control_character',
            offendingChar: "\t",
            oxidColumn: 'oxstreet',
        );
        $validator = $this->stubValidatorReturning([$failure]);

        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $controller = $this->createController($validator);

        ob_start();
        $controller->createCheckoutSession();
        $output = (string) ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertFalse($json['valid']);
        $this->assertCount(1, $json['errors']);

        $error = $json['errors'][0];
        $this->assertSame('street', $error['field']);
        $this->assertSame('control_character', $error['code']);
        $this->assertSame("\t", $error['char']);
        $this->assertSame('delivery', $error['addressKind']);
    }

    // ==========================================
    // T6 — Null offendingChar is serialised as null (not absent)
    // ==========================================
    #[\PHPUnit\Framework\Attributes\Test]
    public function nullOffendingCharIsIncludedAsNull(): void
    {
        $failure = new FieldValidationFailure(
            field: 'postalCode',
            addressKind: 'billing',
            code: 'disallowed_character',
            offendingChar: null,
            oxidColumn: 'oxzip',
        );
        $validator = $this->stubValidatorReturning([$failure]);

        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $controller = $this->createController($validator);

        ob_start();
        $controller->createCheckoutSession();
        $output = (string) ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertFalse($json['valid']);
        $this->assertArrayHasKey('char', $json['errors'][0]);
        $this->assertNull($json['errors'][0]['char']);
    }

    // ==========================================
    // T7 — Phase E: JSON errors carry rendered message
    // ==========================================
    #[\PHPUnit\Framework\Attributes\Test]
    public function emitsErrorsWithRenderedMessage(): void
    {
        $failure = new FieldValidationFailure(
            field: 'firstName',
            addressKind: 'billing',
            code: 'blocked_character',
            offendingChar: ':',
            oxidColumn: 'oxfname',
        );
        $validator = $this->stubValidatorReturning([$failure]);

        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $controller = $this->createController($validator, formatterMessage: "The first name may not contain ':'.");

        ob_start();
        $controller->createCheckoutSession();
        $output = (string) ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertFalse($json['valid']);
        $this->assertSame("The first name may not contain ':'.", $json['errors'][0]['message']);
    }

    // ==========================================
    // Helpers
    // ==========================================

    /**
     * @param FieldValidationFailure[] $failures
     */
    private function stubValidatorReturning(array $failures): UserDataValidatorInterface
    {
        return new class ($failures) implements UserDataValidatorInterface {
            /** @param FieldValidationFailure[] $failures */
            public function __construct(private readonly array $failures)
            {
            }

            public function validateForUser(UserFieldReaderInterface $reader): array
            {
                return $this->failures;
            }

            /** @param array<string, string> $fields */
            public function validateFieldMap(array $fields, string $addressKind = 'billing'): array
            {
                return [];
            }
        };
    }

    private function createBasketMock(bool $empty = false): Basket
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user_validation_test');

        $basket = $this->createMock(Basket::class);
        $basket->method('getProductsCount')->willReturn($empty ? 0 : 1);
        $basket->method('getPaymentId')->willReturn('oe_payments_stripe_wallet');
        $basket->method('getBasketUser')->willReturn($empty ? null : $user);

        return $basket;
    }

    private function createController(
        UserDataValidatorInterface $validator,
        bool $hasEmptyBasket = false,
        ?string $formatterMessage = null,
    ): StripeOrderController&TestableValidationInterface {
        $eventDispatcher = $this->eventDispatcher;
        $cleanupService  = $this->cleanupService;
        $basket          = $this->createBasketMock($hasEmptyBasket);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user_validation_test');

        $helper = new StubControllerRequestHelper();
        $helper->sessionChallengeResult = true;
        $helper->agbConfirmationRequired = false;
        $helper->basket = $basket;

        $formatter = $formatterMessage !== null
            ? $this->stubFormatterReturning($formatterMessage)
            : null;

        /**
         * @implements TestableValidationInterface
         */
        $controller = new class (
            $eventDispatcher,
            $cleanupService,
            $helper,
            $user,
            $validator,
            $hasEmptyBasket,
            $formatter,
        ) extends StripeOrderController implements TestableValidationInterface {
            private int $lastHttpStatusCode = 200;

            public function __construct(
                private readonly EventDispatcherInterface $mockDispatcher,
                private readonly RetryCleanupService $mockCleanupService,
                private readonly StubControllerRequestHelper $stubHelper,
                private readonly User $mockUser,
                private readonly UserDataValidatorInterface $mockValidator,
                private readonly bool $hasEmptyBasket,
                private readonly ?MessageFormatterInterface $mockFormatter,
            ) {
                // Skip OXID bootstrap
            }

            public function getLastHttpStatusCode(): int
            {
                return $this->lastHttpStatusCode;
            }

            protected function getRequestHelper(): ControllerRequestHelper
            {
                return $this->stubHelper;
            }

            protected function getEventDispatcher(): EventDispatcherInterface
            {
                return $this->mockDispatcher;
            }

            protected function getUserDataValidator(): UserDataValidatorInterface
            {
                return $this->mockValidator;
            }

            protected function getUserDataValidationMessageFormatter(): ?MessageFormatterInterface
            {
                return $this->mockFormatter;
            }

            public function getUser(): ?User
            {
                if ($this->hasEmptyBasket) {
                    return null;
                }
                return $this->mockUser;
            }

            public function addTplParam($name, $value): void
            {
                // No-op in tests
            }

            protected function exitWithJson(): void
            {
                // Don't exit in tests
            }

            protected function setHttpResponseCode(int $code): void
            {
                $this->lastHttpStatusCode = $code;
            }

            protected function generateNewSessChallenge(): string
            {
                return 'new_uid_phase_c';
            }

            protected function getServiceFromContainer(string $serviceName): object
            {
                if ($serviceName === RetryCleanupService::class) {
                    return $this->mockCleanupService;
                }
                if ($serviceName === ConfigurationValidatorInterface::class) {
                    return new class {
                        public function getKeyValidationError(): ?string
                        {
                            return null;
                        }
                    };
                }
                if ($serviceName === UserDataValidationMessageFormatter::class) {
                    return new class {
                        public function getPluginModuleId(): string
                        {
                            return 'oe_payments_stripe_wallet';
                        }
                        public function format(string $field, string $code, ?string $char): string
                        {
                            return '';
                        }
                    };
                }
                throw new \RuntimeException("Unknown service: $serviceName");
            }
        };

        return $controller;
    }

    private function stubFormatterReturning(string $message): MessageFormatterInterface
    {
        return new class ($message) implements MessageFormatterInterface {
            public function __construct(private readonly string $message)
            {
            }

            public function getPluginModuleId(): string
            {
                return 'oe_payments_stripe_wallet';
            }

            public function format(string $field, string $code, ?string $offendingChar): string
            {
                return $this->message;
            }
        };
    }
}

/**
 * Marker interface so createController() can return a typed intersection
 * exposing getLastHttpStatusCode() while still being StripeOrderController.
 */
interface TestableValidationInterface
{
    public function getLastHttpStatusCode(): int;
}
