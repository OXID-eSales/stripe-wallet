<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\GraphQL;

use GraphQL\GraphQL;
use GraphQL\Type\Schema;
use OxidEsales\GraphQL\Base\DataType\User;
use OxidEsales\GraphQL\Base\Service\Authentication;
use OxidEsales\PaymentBase\Checkout\Headless\GuestAddress;
use OxidEsales\PaymentBase\Checkout\Headless\GuestDetails;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCancelResult;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutException;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessCheckoutServiceInterface;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessReturnResult;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessStartRequest;
use OxidEsales\PaymentBase\Checkout\Headless\HeadlessStartResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutCancelResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutReturnResult;
use OxidEsales\PaymentBase\GraphQL\DataType\CheckoutStartResult;
use OxidEsales\PaymentBase\Service\TokenServiceInterface;
use OxidEsales\Payments\Stripe\GraphQL\Controller\StripeCheckout;
use OxidEsales\Payments\Stripe\GraphQL\DataType\GuestCheckoutInputFactory;
use OxidEsales\Payments\Stripe\Tests\Support\FixedClassFinder;
use OxidEsales\Eshop\Application\Model\User as EshopUser;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;
use TheCodingMachine\GraphQLite\Security\AuthorizationServiceInterface;
use TheCodingMachine\GraphQLite\SchemaFactory;

/**
 * Integration (shop vendor: GraphQLite and graphql-base are real here, the unit bootstrap only stubs them).
 * GraphQLite itself enforces `#[Logged]` and `#[Right]`: these tests run the
 * three mutations through a real schema with graphql-base's own question
 * ("is the token a logged-in user?", "does its group hold the right?") answered
 * per case, so the annotations - not the controller body - decide. An anonymous
 * token is not "logged" (graphql-base: `!getUser()->isAnonymous()`); the right
 * `PAYMENT_CHECKOUT` alone is the guard of the checkout mutations.
 */
#[Group('integration')]
final class StripeCheckoutAnonymousAccessTest extends TestCase
{
    private const START = 'mutation { stripeCheckoutStart(basketId: "ub-1", confirmTermsAndConditions: true, returnUrl: "https://app.example.com/return") { contractId contractToken } }';
    private const RETURN = 'mutation { stripeCheckoutReturn(contractId: "ctr-1", contractToken: "tok-1", checkoutSessionId: "cs_1") { status orderId } }';
    private const CANCEL = 'mutation { stripeCheckoutCancel(contractId: "ctr-1", contractToken: "tok-1") { cancelled contractState } }';
    private const GUEST_START = 'mutation { stripeCheckoutStart(basketId: "ub-1", confirmTermsAndConditions: true, returnUrl: "https://app.example.com/return", guest: %s) { contractId } }';

    private HeadlessCheckoutServiceInterface&MockObject $checkout;
    private Authentication&MockObject $authentication;
    /** @var list<string> */
    private array $grantedRights = ['PAYMENT_CHECKOUT'];

    protected function setUp(): void
    {
        if (!class_exists(SchemaFactory::class)) {
            self::markTestSkipped('graphql-base / GraphQLite is not installed');
        }
        // Required by path, not by autoload: the shop bootstrap CI uses does not register the Tests\ namespace, and
        // the class implements a kcs/class-finder interface that exists only where GraphQLite does (guard above).
        require_once __DIR__ . '/../../../Support/FixedClassFinder.php';
        $this->checkout = $this->createMock(HeadlessCheckoutServiceInterface::class);
        $this->authentication = $this->createMock(Authentication::class);
    }

    public function testCheckoutStart_AnonymousTokenWithRight_IsAccepted(): void
    {
        $this->asAnonymousToken('anonymous-1');
        $this->checkout->expects($this->once())->method('start')
            ->with($this->callback(static fn(HeadlessStartRequest $r): bool => $r->userId === 'anonymous-1'))
            ->willReturn(new HeadlessStartResult('ctr-1', 'tok-1', 'stripe', '1001', 'https://checkout.stripe.com/c/x', null, 'redirect'));

        $result = $this->execute(self::START);

        self::assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? []));
        self::assertSame('ctr-1', $result['data']['stripeCheckoutStart']['contractId']);
    }

    public function testCheckoutStart_AnonymousTokenWithoutRight_IsRefused(): void
    {
        $this->asAnonymousToken('anonymous-1');
        $this->grantedRights = [];
        $this->checkout->expects($this->never())->method('start');

        $result = $this->execute(self::START);

        self::assertArrayHasKey('errors', $result);
        self::assertNull($result['data']['stripeCheckoutStart'] ?? null);
    }

    public function testCheckoutStart_LoggedInTokenWithRight_IsStillAccepted(): void
    {
        $this->asLoggedInToken('user-1');
        $this->checkout->expects($this->once())->method('start')
            ->willReturn(new HeadlessStartResult('ctr-1', 'tok-1', 'stripe', '1001', null, 'cs_secret', 'embedded'));

        self::assertArrayNotHasKey('errors', $this->execute(self::START));
    }

    public function testCheckoutStart_LoggedInTokenWithoutRight_IsRefused(): void
    {
        $this->asLoggedInToken('user-1');
        $this->grantedRights = [];
        $this->checkout->expects($this->never())->method('start');

        self::assertArrayHasKey('errors', $this->execute(self::START));
    }

    public function testCheckoutReturn_AnotherAnonymousTokenWithTheContractToken_Succeeds(): void
    {
        // The contract token is the only credential (payment-base compares its hash, never the user).
        $this->asAnonymousToken('anonymous-2');
        $this->checkout->method('return')->willReturnCallback(
            static fn(string $contractId, string $token): HeadlessReturnResult => $token === 'tok-1'
                ? new HeadlessReturnResult('committed', 'order-1', '1001', 'committed')
                : throw new HeadlessCheckoutException(HeadlessCheckoutException::INVALID_TOKEN, 'The contract token does not authorise contract ' . $contractId)
        );

        $result = $this->execute(self::RETURN);

        self::assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? []));
        self::assertSame('committed', $result['data']['stripeCheckoutReturn']['status']);
    }

    public function testCheckoutReturn_AnonymousTokenWithAWrongContractToken_Fails(): void
    {
        $this->asAnonymousToken('anonymous-2');
        $this->checkout->method('return')->willThrowException(
            new HeadlessCheckoutException(HeadlessCheckoutException::INVALID_TOKEN, 'The contract token does not authorise contract ctr-1')
        );

        $result = $this->execute(self::RETURN);

        self::assertSame('invalid_token', $result['errors'][0]['extensions']['errorCode']);
    }

    public function testCheckoutReturn_AnonymousTokenWithoutRight_IsRefused(): void
    {
        $this->asAnonymousToken('anonymous-2');
        $this->grantedRights = [];
        $this->checkout->expects($this->never())->method('return');

        self::assertArrayHasKey('errors', $this->execute(self::RETURN));
    }

    public function testCheckoutCancel_AnotherAnonymousTokenWithTheContractToken_Succeeds(): void
    {
        $this->asAnonymousToken('anonymous-2');
        $this->checkout->expects($this->once())->method('cancel')->with('ctr-1', 'tok-1')
            ->willReturn(new HeadlessCancelResult(true, 'ctr-1', 'cancelled'));

        $result = $this->execute(self::CANCEL);

        self::assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? []));
        self::assertTrue($result['data']['stripeCheckoutCancel']['cancelled']);
    }

    public function testCheckoutCancel_AnonymousTokenWithAWrongContractToken_Fails(): void
    {
        $this->asAnonymousToken('anonymous-2');
        $this->checkout->method('cancel')->willThrowException(
            new HeadlessCheckoutException(HeadlessCheckoutException::INVALID_TOKEN, 'The contract token does not authorise contract ctr-1')
        );

        self::assertSame('invalid_token', $this->execute(self::CANCEL)['errors'][0]['extensions']['errorCode']);
    }

    public function testCheckoutStart_GuestInput_ReachesTheHeadlessRequestAsGuestDetails(): void
    {
        $this->asAnonymousToken('anonymous-1');
        $captured = null;
        $this->checkout->expects($this->once())->method('start')
            ->willReturnCallback(function (HeadlessStartRequest $request) use (&$captured): HeadlessStartResult {
                $captured = $request;

                return new HeadlessStartResult('ctr-1', 'tok-1', 'stripe', null, 'https://checkout.stripe.com/c/x', null, 'redirect');
            });

        $result = $this->execute(sprintf(self::GUEST_START, $this->guestInput(withDelivery: true)));

        self::assertArrayNotHasKey('errors', $result, json_encode($result['errors'] ?? []));
        self::assertInstanceOf(HeadlessStartRequest::class, $captured);
        self::assertInstanceOf(GuestDetails::class, $captured->guest);
        self::assertSame('guest@example.com', $captured->guest->email);
        self::assertSame('Grunewaldstr.', $captured->guest->invoiceAddress->street);
        self::assertSame('a7c40f631fc920687.20179984', $captured->guest->invoiceAddress->countryId);
        self::assertSame('Acme', $captured->guest->invoiceAddress->company);
        self::assertSame('', $captured->guest->invoiceAddress->phone, 'optional fields default to empty strings');
        self::assertInstanceOf(GuestAddress::class, $captured->guest->deliveryAddress);
        self::assertSame('Hafenweg', $captured->guest->deliveryAddress->street);
    }

    public function testCheckoutStart_NoGuestInput_LeavesTheGuestEmpty(): void
    {
        $this->asLoggedInToken('user-1');
        $this->checkout->expects($this->once())->method('start')
            ->with($this->callback(static fn(HeadlessStartRequest $r): bool => $r->guest === null))
            ->willReturn(new HeadlessStartResult('ctr-1', 'tok-1', 'stripe', null, 'https://checkout.stripe.com/c/x', null, 'redirect'));

        self::assertArrayNotHasKey('errors', $this->execute(self::START));
    }

    public function testCheckoutStart_GuestWithInvalidEmail_IsAStripeCheckoutErrorWithTheStableCode(): void
    {
        $this->asAnonymousToken('anonymous-1');
        $this->checkout->expects($this->never())->method('start');

        $result = $this->execute(sprintf(self::GUEST_START, $this->guestInput(email: 'not-an-email')));

        self::assertSame('guest_details_required', $result['errors'][0]['extensions']['errorCode']);
        self::assertStringContainsString('email', $result['errors'][0]['message']);
        self::assertStringNotContainsString('not-an-email', $result['errors'][0]['message'], 'the message names the field, never the value');
    }

    public function testCheckoutStart_GuestWithAnEmptyRequiredAddressField_IsAStripeCheckoutErrorWithTheStableCode(): void
    {
        $this->asAnonymousToken('anonymous-1');
        $this->checkout->expects($this->never())->method('start');

        $result = $this->execute(sprintf(self::GUEST_START, $this->guestInput(city: '')));

        self::assertSame('guest_details_required', $result['errors'][0]['extensions']['errorCode']);
        self::assertStringContainsString('city', $result['errors'][0]['message']);
    }

    public function testCheckoutStart_GuestWithARegisteredEmail_IsAStripeCheckoutErrorAccountExists(): void
    {
        $this->asAnonymousToken('anonymous-1');
        $this->checkout->method('start')->willThrowException(
            new HeadlessCheckoutException(HeadlessCheckoutException::ACCOUNT_EXISTS, 'An account with this e-mail exists: log in')
        );

        $result = $this->execute(sprintf(self::GUEST_START, $this->guestInput()));

        self::assertSame('account_exists', $result['errors'][0]['extensions']['errorCode']);
        self::assertSame('requesterror', $result['errors'][0]['extensions']['category'] ?? 'requesterror');
    }

    // ---------------------------------------------------------------- helpers

    private function asAnonymousToken(string $userId): void
    {
        $this->authentication->method('isLogged')->willReturn(false);
        $this->authentication->method('getUser')->willReturn(new User($this->userModel($userId), true));
    }

    private function asLoggedInToken(string $userId): void
    {
        $this->authentication->method('isLogged')->willReturn(true);
        $this->authentication->method('getUser')->willReturn(new User($this->userModel($userId)));
    }

    private function userModel(string $userId): EshopUser
    {
        $model = $this->createMock(EshopUser::class);
        $model->method('getId')->willReturn($userId);

        return $model;
    }

    /**
     * @return array<string, mixed>
     */
    private function execute(string $query): array
    {
        return GraphQL::executeQuery($this->schema(), $query)->toArray();
    }

    private function schema(): Schema
    {
        $controller = new StripeCheckout($this->checkout, $this->tokenService(), $this->authentication);
        $container = new class ([StripeCheckout::class => $controller]) implements ContainerInterface {
            /** @param array<string, object> $services */
            public function __construct(private readonly array $services)
            {
            }

            public function get(string $id): object
            {
                return $this->services[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->services[$id]);
            }
        };

        $rights = $this->grantedRights;
        $authorization = new class ($rights) implements AuthorizationServiceInterface {
            /** @param list<string> $rights */
            public function __construct(private readonly array $rights)
            {
            }

            public function isAllowed(string $right, mixed $subject = null): bool
            {
                return in_array($right, $this->rights, true);
            }
        };

        $factory = new SchemaFactory(new Psr16Cache(new ArrayAdapter()), $container);
        $factory->setFinder(new FixedClassFinder([
            StripeCheckout::class,
            GuestCheckoutInputFactory::class,
            CheckoutStartResult::class,
            CheckoutReturnResult::class,
            CheckoutCancelResult::class,
        ]));
        $factory->addControllerNamespace('OxidEsales\\Payments\\Stripe\\GraphQL\\Controller');
        $factory->addTypeNamespace('OxidEsales\\PaymentBase\\GraphQL\\DataType');
        $factory->addTypeNamespace('OxidEsales\\Payments\\Stripe\\GraphQL\\DataType');
        $factory->setAuthenticationService($this->authentication);
        $factory->setAuthorizationService($authorization);

        return $factory->createSchema();
    }

    private function tokenService(): TokenServiceInterface
    {
        $tokens = $this->createMock(TokenServiceInterface::class);
        $tokens->method('generateToken')->willReturn('stripe-tok');

        return $tokens;
    }

    private function guestInput(
        string $email = 'guest@example.com',
        string $city = 'Freiburg',
        bool $withDelivery = false
    ): string {
        $invoice = sprintf(
            '{firstName: "Gerda", lastName: "Guest", street: "Grunewaldstr.", streetNumber: "1", zipCode: "79098", city: "%s", countryId: "a7c40f631fc920687.20179984", company: "Acme"}',
            $city
        );
        $delivery = $withDelivery
            ? ', deliveryAddress: {firstName: "Dora", lastName: "Dock", street: "Hafenweg", streetNumber: "9", zipCode: "20457", city: "Hamburg", countryId: "a7c40f631fc920687.20179984"}'
            : '';

        return sprintf('{email: "%s", invoiceAddress: %s%s}', $email, $invoice, $delivery);
    }
}
