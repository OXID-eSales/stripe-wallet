<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\Eshop\Application\Model\Basket;
use OxidEsales\Eshop\Application\Model\User;
use OxidEsales\Payments\Stripe\Controller\BasketNotBuyableException;
use OxidEsales\Payments\Stripe\Controller\ControllerRequestHelper;
use OxidEsales\Payments\Stripe\Controller\StripeOrderController;
use OxidEsales\Payments\Stripe\EventSystem\Event\StripeCheckoutSessionRequestEvent;
use OxidEsales\Payments\Stripe\Service\BasketBuyabilityValidator;
use OxidEsales\Payments\Stripe\Service\BuyabilityFailure;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidatorInterface;
use OxidEsales\Payments\Stripe\Service\LanguageTranslatorInterface;
use OxidEsales\Payments\Stripe\Service\OxidLanguageTranslator;
use OxidEsales\Payments\Stripe\Service\RetryCleanupService;
use OxidEsales\Payments\Stripe\Service\UserDataValidatorInterface;
use OxidEsales\Payments\Stripe\Service\UserFieldReaderInterface;
use OxidEsales\PaymentBase\Adapter\Exception\ShopOrderException;
use OxidEsales\PaymentBase\EventSystem\EventDispatcherInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Story 2 (unbuyable-article-checkout): the pre-dispatch buyability gate inside
 * StripeOrderController::createCheckoutSession().
 *
 * When any cart item is no longer buyable, the checkout-session event MUST NOT
 * be dispatched (no contract, no draft order) and the controller emits a
 * structured HTTP 409 carrying a per-product error — reusing the frontend
 * `errors[]` contract already used for user-data validation.
 *
 * Uses the testable-subclass pattern; the controller under test is the real
 * StripeOrderController with seams overridden.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\Controller\StripeOrderController::class)]
#[\PHPUnit\Framework\Attributes\Group('buyability')]
final class StripeOrderControllerBuyabilityTest extends TestCase
{
    private EventDispatcherInterface&MockObject $eventDispatcher;
    private RetryCleanupService&MockObject $cleanupService;

    protected function setUp(): void
    {
        $this->eventDispatcher = $this->createMock(EventDispatcherInterface::class);
        $this->cleanupService  = $this->createMock(RetryCleanupService::class);
    }

    public function testCreateCheckoutSession_WhenArticleNotBuyable_SetsHttp409(): void
    {
        $controller = $this->createController([
            new BuyabilityFailure('art-2', 'Sold Out B', BasketBuyabilityValidator::REASON_NOT_BUYABLE),
        ]);

        ob_start();
        $controller->createCheckoutSession();
        ob_get_clean();

        $this->assertSame(409, $controller->getLastHttpStatusCode());
    }

    public function testCreateCheckoutSession_WhenArticleNotBuyable_DoesNotDispatchCheckoutEvent(): void
    {
        $this->eventDispatcher->expects($this->never())->method('dispatch');

        $controller = $this->createController([
            new BuyabilityFailure('art-2', 'Sold Out B', BasketBuyabilityValidator::REASON_NOT_BUYABLE),
        ]);

        ob_start();
        $controller->createCheckoutSession();
        ob_get_clean();
    }

    public function testCreateCheckoutSession_WhenArticleNotBuyable_EmitsErrorsArrayWithPerProductMessage(): void
    {
        $controller = $this->createController(
            [
                new BuyabilityFailure('art-1', 'Sold Out A', BasketBuyabilityValidator::REASON_NOT_BUYABLE),
                new BuyabilityFailure('art-3', 'Sold Out C', BasketBuyabilityValidator::REASON_NOT_BUYABLE),
            ],
            translatedMessage: 'Product is not purchasable',
        );

        ob_start();
        $controller->createCheckoutSession();
        $output = (string) ob_get_clean();

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertFalse($json['valid']);
        $this->assertCount(2, $json['errors']);

        $this->assertSame('article_not_buyable', $json['errors'][0]['code']);
        $this->assertSame('art-1', $json['errors'][0]['articleId']);
        $this->assertSame('Sold Out A', $json['errors'][0]['productTitle']);
        $this->assertSame('Product is not purchasable', $json['errors'][0]['message']);
        $this->assertSame('art-3', $json['errors'][1]['articleId']);
    }

    public function testCreateCheckoutSession_WhenAllArticlesBuyable_DispatchesEventNormally(): void
    {
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

        $controller = $this->createController([]);

        ob_start();
        $controller->createCheckoutSession();
        ob_get_clean();

        $this->assertSame(200, $controller->getLastHttpStatusCode());
    }

    public function testBuildCheckoutEventContext_WhenNotBuyable_ThrowsBasketNotBuyableExceptionBeforeContextBuilt(): void
    {
        $failures = [
            new BuyabilityFailure('art-2', 'Sold Out B', BasketBuyabilityValidator::REASON_NOT_BUYABLE),
        ];
        $controller = $this->createController($failures);

        $method = new \ReflectionMethod(StripeOrderController::class, 'buildCheckoutEventContext');

        try {
            $method->invoke($controller, $controller->getRequestHelper());
            $this->fail('Expected BasketNotBuyableException was not thrown');
        } catch (BasketNotBuyableException $e) {
            $this->assertSame($failures, $e->getFailures());
        }
    }

    // ==========================================
    // Story 3 — defense-in-depth: a product turning unbuyable *during*
    // finalizeOrder surfaces as a ShopOrderException('article_not_buyable')
    // from the dispatched event. It must become a 409, never a raw 500.
    // ==========================================

    public function testCreateCheckoutSession_WhenShopOrderExceptionIsArticleNotBuyable_SetsHttp409NotShown500(): void
    {
        $this->eventDispatcher
            ->method('dispatch')
            ->willThrowException(new ShopOrderException(
                message: 'ERROR_MESSAGE_ARTICLE_ARTICLE_NOT_BUYABLE',
                errorCode: 'article_not_buyable',
            ));

        $controller = $this->createController([]);

        ob_start();
        $controller->createCheckoutSession();
        $output = (string) ob_get_clean();

        $this->assertSame(409, $controller->getLastHttpStatusCode());

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertFalse($json['valid']);
        $this->assertSame('article_not_buyable', $json['errors'][0]['code']);
    }

    public function testCreateCheckoutSession_WhenShopOrderExceptionIsOtherErrorCode_Still500(): void
    {
        $this->eventDispatcher
            ->method('dispatch')
            ->willThrowException(new ShopOrderException(
                message: 'Order finalization failed',
                errorCode: 'payment_error',
            ));

        $controller = $this->createController([]);

        ob_start();
        $controller->createCheckoutSession();
        $output = (string) ob_get_clean();

        $this->assertSame(500, $controller->getLastHttpStatusCode());

        $json = json_decode($output, true);
        $this->assertIsArray($json);
        $this->assertArrayHasKey('error', $json);
    }

    /**
     * Regression guard: the real getLanguageTranslator() seam must NOT resolve
     * a private, injection-only container service (that throws at runtime).
     * It creates the dependency-free translator directly.
     */
    public function testGetLanguageTranslator_ReturnsContainerFreeTranslator(): void
    {
        $controller = new class extends StripeOrderController {
            public function __construct()
            {
                // Skip OXID bootstrap.
            }

            public function exposeLanguageTranslator(): LanguageTranslatorInterface
            {
                return $this->getLanguageTranslator();
            }
        };

        $translator = $controller->exposeLanguageTranslator();

        $this->assertInstanceOf(OxidLanguageTranslator::class, $translator);
    }

    // ==========================================
    // Helpers
    // ==========================================

    /**
     * @param BuyabilityFailure[] $buyabilityFailures
     */
    private function createController(
        array $buyabilityFailures,
        string $translatedMessage = 'Product is not purchasable',
    ): StripeOrderController&TestableBuyabilityInterface {
        $eventDispatcher = $this->eventDispatcher;
        $cleanupService  = $this->cleanupService;

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('user_buyability_test');

        $basket = $this->createMock(Basket::class);
        $basket->method('getProductsCount')->willReturn(1);
        $basket->method('getContents')->willReturn([]);

        $helper = new StubControllerRequestHelper();
        $helper->sessionChallengeResult  = true;
        $helper->agbConfirmationRequired = false;
        $helper->basket                  = $basket;

        return new class (
            $eventDispatcher,
            $cleanupService,
            $helper,
            $user,
            $this->passingUserValidator(),
            $this->stubBuyabilityValidator($buyabilityFailures),
            $this->stubTranslator($translatedMessage),
        ) extends StripeOrderController implements TestableBuyabilityInterface {
            private int $lastHttpStatusCode = 200;

            public function __construct(
                private readonly EventDispatcherInterface $mockDispatcher,
                private readonly RetryCleanupService $mockCleanupService,
                private readonly StubControllerRequestHelper $stubHelper,
                private readonly User $mockUser,
                private readonly UserDataValidatorInterface $mockUserValidator,
                private readonly BasketBuyabilityValidator $mockBuyabilityValidator,
                private readonly LanguageTranslatorInterface $mockTranslator,
            ) {
                // Skip OXID bootstrap
            }

            public function getLastHttpStatusCode(): int
            {
                return $this->lastHttpStatusCode;
            }

            public function getRequestHelper(): ControllerRequestHelper
            {
                return $this->stubHelper;
            }

            protected function getEventDispatcher(): EventDispatcherInterface
            {
                return $this->mockDispatcher;
            }

            protected function getUserDataValidator(): UserDataValidatorInterface
            {
                return $this->mockUserValidator;
            }

            protected function getBasketBuyabilityValidator(): BasketBuyabilityValidator
            {
                return $this->mockBuyabilityValidator;
            }

            protected function getLanguageTranslator(): LanguageTranslatorInterface
            {
                return $this->mockTranslator;
            }

            public function getUser(): ?User
            {
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
                return 'new_uid_buyability';
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
                throw new \RuntimeException("Unknown service: $serviceName");
            }
        };
    }

    private function passingUserValidator(): UserDataValidatorInterface
    {
        return new class implements UserDataValidatorInterface {
            public function validateForUser(UserFieldReaderInterface $reader): array
            {
                return [];
            }

            /** @param array<string, string> $fields */
            public function validateFieldMap(array $fields, string $addressKind = 'billing'): array
            {
                return [];
            }
        };
    }

    /**
     * @param BuyabilityFailure[] $failures
     */
    private function stubBuyabilityValidator(array $failures): BasketBuyabilityValidator
    {
        return new class ($failures) extends BasketBuyabilityValidator {
            /** @param BuyabilityFailure[] $failures */
            public function __construct(private readonly array $failures)
            {
            }

            public function validate(Basket $basket): array
            {
                return $this->failures;
            }
        };
    }

    private function stubTranslator(string $message): LanguageTranslatorInterface
    {
        return new class ($message) implements LanguageTranslatorInterface {
            public function __construct(private readonly string $message)
            {
            }

            public function translateString(string $key): string
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
interface TestableBuyabilityInterface
{
    public function getLastHttpStatusCode(): int;
}
