<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Integration\Stripe\Logging;

use DateTimeImmutable;
use OxidEsales\PaymentBase\Logging\ThrowableSummary;
use OxidEsales\PaymentBase\Repository\WebhookLogRepositoryInterface;
use OxidEsales\PaymentBase\Service\FileLoggerInterface;
use OxidEsales\PaymentBase\Webhook\WebhookEvent;
use OxidEsales\PaymentBase\Webhook\WebhookPayloadSanitizer;
use OxidEsales\PaymentBase\Webhook\WebhookRequest;
use OxidEsales\PaymentBase\Webhook\WebhookResult;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Logging\StripeThrowableSummaryExtractor;
use OxidEsales\Payments\Stripe\Service\CancelAuthorizationService;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Service\RequestLogService;
use OxidEsales\Payments\Stripe\Service\WebhookLogService;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookEventHandlerInterface;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookOutcome;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookPiiKeyProvider;
use OxidEsales\Payments\Stripe\Webhook\StripeWebhookProcessor;
use OxidEsales\Payments\Stripe\Webhook\WebhookOriginPolicyInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use RuntimeException;
use Stripe\Exception\InvalidRequestException;

/**
 * VR-18 L6 (stripe): the log-content guard. A happy webhook delivery and the failure paths run with fixture personal
 * data in the payload and in the exception messages a PSP or the database would produce; every sink a log line can
 * reach (PSR-3 logger, the module's file loggers, the webhook claim row's error column, the sanitised payload that
 * is stored) is then searched for the fixture data. A leak is a red test, whichever log carried it.
 *
 * The checkout itself (customer fills the form, pays, returns) is proven by the e2e suite; what this test pins is that
 * no log the module writes on the way holds content.
 */
#[\PHPUnit\Framework\Attributes\Group('integration')]
#[\PHPUnit\Framework\Attributes\Group('sprint-18')]
final class NoPiiInLogsTest extends TestCase
{
    private const EMAIL = 'fixture.buyer@example.test';
    private const STREET = 'Fixturestrasse 42';
    private const CARD_TOKEN = 'tkn_fixture_card_token';
    private const CONTRACT_TOKEN = 'contract_token=fixture-return-token';
    private const CLIENT_SECRET = 'pi_fixture_secret_fixture';
    private const WEBHOOK_SECRET = 'whsec_fixture';

    /** @var list<string> */
    private const FORBIDDEN = [
        self::EMAIL,
        self::STREET,
        'tkn_',
        'contract_token=',
        'client_secret',
        self::CLIENT_SECRET,
    ];

    private RecordingPsrLogger $psrLogger;
    private RecordingFileLogger $fileLogger;
    /** @var list<string> */
    private array $claimRowErrors = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->psrLogger = new RecordingPsrLogger();
        $this->fileLogger = new RecordingFileLogger();
        $this->claimRowErrors = [];
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aHappyWebhookDeliveryLogsIdsOnly(): void
    {
        $payload = $this->checkoutSessionCompletedPayload();
        $logService = new WebhookLogService($this->fileLogger);

        $logService->logReceived($payload, 'sig', '203.0.113.7');
        $result = $this->deliver($payload, $this->handlerReturning(WebhookResult::success('contract_fulfilled')));
        $logService->logResult($payload, $result, 200, 12, 'contract-1');

        self::assertTrue($result->isSuccess());
        $this->assertNothingLeaked();
        self::assertStringContainsString('cs_fixture_1', $this->fileLogger->dump(), 'the ids are what the log is for');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function theStoredPayloadIsSanitisedByTheProvidersKeys(): void
    {
        $sanitizer = new WebhookPayloadSanitizer([new StripeWebhookPiiKeyProvider()]);

        $stored = (string) json_encode($sanitizer->sanitize((array) json_decode($this->checkoutSessionCompletedPayload(), true)));

        // The key name `client_secret` stays in a stored payload (its value is what is redacted).
        foreach (array_diff(self::FORBIDDEN, ['client_secret']) as $needle) {
            self::assertStringNotContainsString($needle, $stored, "sanitised payload holds '{$needle}'");
        }
        self::assertStringContainsString('evt_fixture_1', $stored);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aFailingHandlerWhoseMessageCarriesPersonalDataLogsItsSummaryOnly(): void
    {
        $payload = $this->checkoutSessionCompletedPayload();
        $failing = $this->handlerThrowing(new RuntimeException($this->contentRichMessage(), 23));

        $result = $this->deliver($payload, $failing);

        self::assertTrue($result->isFailure());
        $this->assertNothingLeaked();
        self::assertSame('Webhook processing failed', $result->error, 'the PSP gets a fixed text');
        self::assertNotEmpty($this->claimRowErrors, 'the claim row still says that it failed');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function aStripeApiErrorInAnAdminActionLogsTypeCodeAndRequestIdOnly(): void
    {
        $adapter = $this->createMock(StripeAdapterInterface::class);
        $adapter->method('cancelPaymentIntent')->willThrowException($this->stripeError());
        $factory = $this->createMock(StripeAdapterFactoryInterface::class);
        $factory->method('getStripeAdapter')->willReturn($adapter);
        $service = new CancelAuthorizationService($factory, $this->summary(), $this->psrLogger);

        $service->cancelAuthorization('pi_fixture_1');

        $this->assertNothingLeaked();
        self::assertStringContainsString('req_fixture_1', $this->psrLogger->dump(), 'the request id is what support asks for');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function theRequestLogOfAFailedActionHoldsNoExceptionMessage(): void
    {
        $requestLog = new RequestLogService($this->fileLogger, $this->summary(), $this->psrLogger);

        $requestLog->logException('capture', new RuntimeException($this->contentRichMessage(), 500), 'contract-1', 1);

        $this->assertNothingLeaked();
        self::assertStringContainsString('contract-1', $this->fileLogger->dump());
    }

    private function deliver(string $payload, StripeWebhookEventHandlerInterface $handler): WebhookResult
    {
        $config = $this->createMock(ModuleConfigurationServiceInterface::class);
        $config->method('getWebhookSecret')->willReturn(self::WEBHOOK_SECRET);
        $origin = $this->createMock(WebhookOriginPolicyInterface::class);
        $origin->method('refusalFor')->willReturn(null);
        $processor = new StripeWebhookProcessor(
            $this->claimRepository(),
            $this->psrLogger,
            $config,
            $origin,
            [$handler],
            $this->summary()
        );

        return $processor->process(new WebhookRequest(
            payload: $payload,
            signature: self::stripeSignature($payload),
            remoteIp: '203.0.113.7',
            receivedAt: new DateTimeImmutable()
        ));
    }

    private function claimRepository(): WebhookLogRepositoryInterface
    {
        $repository = $this->createMock(WebhookLogRepositoryInterface::class);
        $repository->method('claimEvent')->willReturn(true);
        $repository->method('updateStatus')->willReturnCallback(
            function (string $eventId, string $status, ?string $error = null): void {
                $this->claimRowErrors[] = (string) $error;
            }
        );

        return $repository;
    }

    private function handlerReturning(WebhookResult $result): StripeWebhookEventHandlerInterface
    {
        $handler = $this->createMock(StripeWebhookEventHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willReturn(StripeWebhookOutcome::of($result, 'contract-1'));

        return $handler;
    }

    private function handlerThrowing(RuntimeException $failure): StripeWebhookEventHandlerInterface
    {
        $handler = $this->createMock(StripeWebhookEventHandlerInterface::class);
        $handler->method('supports')->willReturn(true);
        $handler->method('handle')->willThrowException($failure);

        return $handler;
    }

    private function summary(): ThrowableSummary
    {
        return new ThrowableSummary([new StripeThrowableSummaryExtractor()]);
    }

    private function contentRichMessage(): string
    {
        return 'Request body: {"email":"' . self::EMAIL . '","address":"' . self::STREET . '","card":"' . self::CARD_TOKEN
            . '","return":"https://shop.example/?' . self::CONTRACT_TOKEN . '","client_secret":"' . self::CLIENT_SECRET . '"}';
    }

    private function stripeError(): InvalidRequestException
    {
        return InvalidRequestException::factory(
            $this->contentRichMessage(),
            400,
            '{}',
            ['error' => ['type' => 'invalid_request_error', 'code' => 'resource_missing']],
            ['Request-Id' => 'req_fixture_1'],
            'resource_missing'
        );
    }

    private function checkoutSessionCompletedPayload(): string
    {
        return (string) json_encode([
            'id' => 'evt_fixture_1',
            'object' => 'event',
            'type' => 'checkout.session.completed',
            'created' => time(),
            'livemode' => false,
            'data' => ['object' => [
                'id' => 'cs_fixture_1',
                'payment_status' => 'paid',
                'payment_intent' => 'pi_fixture_1',
                'client_secret' => self::CLIENT_SECRET,
                'customer_details' => ['email' => self::EMAIL, 'name' => 'Fixture Buyer'],
                'shipping' => ['address' => ['line1' => self::STREET, 'city' => 'Freiburg']],
                'metadata' => ['contract_id' => 'contract-1', 'return' => self::CONTRACT_TOKEN, 'card' => self::CARD_TOKEN],
            ]],
        ]);
    }

    private function assertNothingLeaked(): void
    {
        $this->assertDoesNotLeak($this->psrLogger->dump(), 'PSR-3 (shop) log');
        $this->assertDoesNotLeak($this->fileLogger->dump(), 'module file log');
        $this->assertDoesNotLeak(implode("\n", $this->claimRowErrors), 'webhook claim row error');
    }

    private function assertDoesNotLeak(string $log, string $which): void
    {
        foreach (self::FORBIDDEN as $needle) {
            self::assertStringNotContainsString($needle, $log, "{$which} holds '{$needle}'");
        }
    }

    /**
     * Stripe's `Stripe-Signature` header for this payload (t=…,v1=HMAC-SHA256). Built here: the CI integration run
     * does not autoload the module's tests/Helper namespace.
     */
    private static function stripeSignature(string $payload): string
    {
        $timestamp = time();

        return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $payload, self::WEBHOOK_SECRET);
    }
}

/**
 * @internal
 */
final class RecordingPsrLogger extends AbstractLogger
{
    /** @var list<string> */
    private array $lines = [];

    /**
     * @param mixed[] $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->lines[] = $level . ' ' . (string) $message . ' ' . json_encode($context);
    }

    public function dump(): string
    {
        return implode("\n", $this->lines);
    }
}

/**
 * @internal
 */
final class RecordingFileLogger implements FileLoggerInterface
{
    /** @var list<string> */
    private array $lines = [];

    public function log(string $message, array $context = []): void
    {
        $this->lines[] = $message . ' ' . json_encode($context);
    }

    public function dump(): string
    {
        return implode("\n", $this->lines);
    }
}
