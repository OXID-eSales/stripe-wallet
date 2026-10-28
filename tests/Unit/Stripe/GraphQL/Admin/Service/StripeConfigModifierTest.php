<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Service;

use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminRefusal;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigChange;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigChangeValidator;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigModifier;
use OxidEsales\Payments\Stripe\Service\ConfigurationValidator;
use OxidEsales\Payments\Stripe\Service\ModuleConfigurationServiceInterface;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\ConfigFixtures;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\CountingStepUp;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\FakeSuperadminCheck;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\InMemoryStripeSettings;
use OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Support\RecordingStripeAudit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StripeConfigModifierTest extends TestCase
{
    private InMemoryStripeSettings $settings;
    private CountingStepUp $stepUp;
    private RecordingStripeAudit $audit;
    private FakeSuperadminCheck $superadmin;

    protected function setUp(): void
    {
        $this->settings = new InMemoryStripeSettings(ConfigFixtures::snapshot());
        $this->stepUp = new CountingStepUp();
        $this->audit = new RecordingStripeAudit();
        $this->superadmin = new FakeSuperadminCheck();
    }

    public function testModify_AHarmlessField_IsSavedWithoutAStepUp(): void
    {
        $result = $this->modifier()->modify($this->change(captureMode: 'manual'));

        self::assertNull($result->refusal());
        self::assertSame('manual', $result->config()->captureMode);
        self::assertSame('manual', $this->settings->snapshot()->captureMode);
        self::assertSame(0, $this->stepUp->required);
    }

    public function testModify_AFieldThatIsNotGiven_StaysAsItWas(): void
    {
        $this->modifier()->modify($this->change(logLevel: 'debug'));

        $saved = $this->settings->snapshot();
        self::assertSame('automatic', $saved->captureMode);
        self::assertTrue($saved->removeByBillingCountry);
        self::assertSame('debug', $saved->logLevel);
    }

    public function testModify_AnEmptySecret_LeavesTheStoredSecretAlone(): void
    {
        $this->modifier()->modify($this->change(testToken: '', captureMode: 'manual'));

        self::assertSame('sk_test_AAAAAAAAAAstored', $this->settings->token('test'));
        self::assertSame(0, $this->stepUp->required);
    }

    public function testModify_ANewSecret_IsStoredNeedsAStepUpAndIsNeverReturned(): void
    {
        $result = $this->modifier()->modify($this->change(testToken: 'sk_test_AAAAAAAAAAbrandnew'));

        self::assertNull($result->refusal());
        self::assertSame('sk_test_AAAAAAAAAAbrandnew', $this->settings->token('test'));
        self::assertSame(1, $this->stepUp->required, 'writing a secret asks for a step-up');
        self::assertStringNotContainsString('brandnew', (string) json_encode($result->config()));
    }

    public function testModify_ANewWebhookSecretOrPlatformKey_AlsoNeedsAStepUp(): void
    {
        $this->modifier()->modify($this->change(webhookSecret: 'whsec_AAAAAAAAAAAA'));
        $this->modifier()->modify($this->change(testPlatformKey: 'sk_test_PLATFORMplatform'));

        self::assertSame(2, $this->stepUp->required);
    }

    public function testModify_AStepUpThatIsMissing_StopsEverythingAndIsAudited(): void
    {
        $this->stepUp = new CountingStepUp(proven: false);

        try {
            $this->modifier()->modify($this->change(testToken: 'sk_test_AAAAAAAAAAbrandnew'));
            self::fail('the step-up must stop the write');
        } catch (RuntimeException $stepUpRequired) {
            self::assertSame('step_up_required', $stepUpRequired->getMessage());
        }

        self::assertSame(0, $this->settings->writes);
        self::assertSame([['operation' => 'adminStripeConfigModify', 'fields' => ['testToken'], 'outcome' => RuntimeException::class]], $this->audit->rows);
    }

    public function testModify_SwitchingToLive_NeedsAStepUp(): void
    {
        $this->stepUp = new CountingStepUp(proven: false);

        $this->expectException(RuntimeException::class);
        try {
            $this->modifier()->modify($this->change(mode: 'live'));
        } finally {
            self::assertSame('test', $this->settings->snapshot()->mode);
            self::assertSame(0, $this->settings->writes);
        }
    }

    public function testModify_SwitchingToLive_WithAStepUpSwitches(): void
    {
        $result = $this->modifier()->modify($this->change(mode: 'live'));

        self::assertNull($result->refusal());
        self::assertSame('live', $this->settings->snapshot()->mode);
        self::assertSame(1, $this->stepUp->required);
    }

    public function testModify_StayingInLive_NeedsNoStepUpForAHarmlessField(): void
    {
        $this->settings = new InMemoryStripeSettings(ConfigFixtures::snapshot(['mode' => 'live']));

        $this->modifier()->modify($this->change(mode: 'live', removeByBasketCurrency: false));

        self::assertSame(0, $this->stepUp->required);
    }

    public function testModify_AStaleVersion_IsAConflictAndNothingIsSaved(): void
    {
        $result = $this->modifier()->modify(new StripeConfigChange(expectedVersion: 'stale', captureMode: 'manual'));

        self::assertSame(StripeAdminRefusal::CONFLICT, $result->refusal()?->kind);
        self::assertSame('stale', $result->refusal()->expectedVersion);
        self::assertSame($this->settings->snapshot()->version(), $result->refusal()->actualVersion);
        self::assertSame(0, $this->settings->writes);
    }

    public function testModify_AnInvalidInput_IsRefusedAtItsFieldAndNothingIsSaved(): void
    {
        $result = $this->modifier()->modify($this->change(captureMode: 'later', logLevel: 'debug'));

        self::assertSame('captureMode', $result->refusal()?->field);
        self::assertSame(0, $this->settings->writes);
    }

    public function testModify_APublishableKeyOfAnotherAccountThanTheStoredToken_IsRefusedAtTheField(): void
    {
        $result = $this->modifier()->modify($this->change(testPublishableKey: 'pk_test_ZZZZZZZZZZbbbb', captureMode: 'manual'));

        self::assertSame('testPublishableKey', $result->refusal()?->field);
        self::assertSame('key_mismatch', $result->refusal()->code);
        self::assertStringNotContainsString('ZZZZZZZZZZ', $result->refusal()->message);
        self::assertStringNotContainsString('AAAAAAAAAA', $result->refusal()->message);
        self::assertSame(0, $this->settings->writes, 'the other fields of the call are not saved either');
    }

    public function testModify_ANewTokenOfAnotherAccountThanTheStoredPublishableKey_IsRefusedAtTheToken(): void
    {
        $result = $this->modifier()->modify($this->change(testToken: 'sk_test_ZZZZZZZZZZnewsecret'));

        self::assertSame('testToken', $result->refusal()?->field);
        self::assertSame('key_mismatch', $result->refusal()->code);
        self::assertStringNotContainsString('newsecret', $result->refusal()->message);
        self::assertSame('sk_test_AAAAAAAAAAstored', $this->settings->token('test'));
    }

    public function testModify_AKeyWhoseCounterpartIsNotSetYet_IsNotAMismatch(): void
    {
        $result = $this->modifier()->modify($this->change(livePublishableKey: 'pk_live_BBBBBBBBBBbbbb'));

        self::assertNull($result->refusal(), 'the live token comes later');
    }

    public function testModify_BothKeysTogether_AreJudgedAsAPair(): void
    {
        $result = $this->modifier()->modify($this->change(liveToken: 'sk_live_BBBBBBBBBBsecret', livePublishableKey: 'pk_live_BBBBBBBBBBbbbb'));

        self::assertNull($result->refusal());
        self::assertTrue($this->settings->snapshot()->liveTokenSet);
    }

    public function testModify_EveryCallIsAuditedOnceWithFieldNamesOnly(): void
    {
        $this->modifier()->modify($this->change(captureMode: 'manual', testToken: 'sk_test_AAAAAAAAAAbrandnew'));
        $this->modifier()->modify($this->change(captureMode: 'later'));

        self::assertSame(
            [
                ['operation' => 'adminStripeConfigModify', 'fields' => ['captureMode', 'testToken'], 'outcome' => 'ok'],
                ['operation' => 'adminStripeConfigModify', 'fields' => ['captureMode'], 'outcome' => 'not_allowed'],
            ],
            $this->audit->rows,
        );
        self::assertStringNotContainsString('brandnew', (string) json_encode($this->audit->rows));
    }

    /**
     * @return iterable<string, array{array<string, string>, string}>
     */
    public static function secretChanges(): iterable
    {
        yield 'test token' => [['testToken' => 'sk_test_AAAAAAAAAAbrandnew'], 'testToken'];
        yield 'live token' => [['liveToken' => 'sk_live_BBBBBBBBBBbrandnew'], 'liveToken'];
        yield 'test platform key' => [['testPlatformKey' => 'sk_test_PLATFORMplatform'], 'testPlatformKey'];
        yield 'webhook secret' => [['webhookSecret' => 'whsec_AAAAAAAAAAAA'], 'webhookSecret'];
    }

    /**
     * @param array<string, string> $secret
     */
    #[DataProvider('secretChanges')]
    public function testModify_ASecretFromANonSuperadmin_IsRefusedAtTheFieldBeforeAnyStepUpAndNothingIsSaved(array $secret, string $field): void
    {
        $this->superadmin = new FakeSuperadminCheck(false);

        $result = $this->modifier()->modify($this->change(...$secret, captureMode: 'manual'));

        self::assertSame('superadmin_required', $result->refusal()?->code);
        self::assertSame($field, $result->refusal()->field);
        self::assertSame(0, $this->stepUp->required, 'no second factor is asked for something the admin may not do');
        self::assertSame('automatic', $this->settings->snapshot()->captureMode, 'the whole call is refused');
        self::assertSame('sk_test_AAAAAAAAAAstored', $this->settings->token('test'));
        self::assertSame('superadmin_required', $this->audit->rows[0]['outcome'], 'audited as the refusal it is');
        self::assertEqualsCanonicalizing([$field, 'captureMode'], $this->audit->rows[0]['fields']);
    }

    public function testModify_AHarmlessChangeFromANonSuperadmin_IsSavedAndTheCheckIsNotEvenAsked(): void
    {
        $this->superadmin = new FakeSuperadminCheck(false);

        $result = $this->modifier()->modify($this->change(captureMode: 'manual', testToken: '', testPublishableKey: 'pk_test_AAAAAAAAAAnewpub'));

        self::assertNull($result->refusal());
        self::assertSame('manual', $this->settings->snapshot()->captureMode);
        self::assertSame(0, $this->superadmin->asked);
    }

    public function testModify_SwitchingToLiveWithoutASecret_NeedsNoSuperadmin(): void
    {
        $this->superadmin = new FakeSuperadminCheck(false);

        $result = $this->modifier()->modify($this->change(mode: 'live'));

        self::assertNull($result->refusal());
        self::assertSame(1, $this->stepUp->required, 'the step-up stays what it was');
    }

    public function testModify_ASecretFromASuperadmin_StillAsksForTheStepUp(): void
    {
        $result = $this->modifier()->modify($this->change(testToken: 'sk_test_AAAAAAAAAAbrandnew'));

        self::assertNull($result->refusal());
        self::assertSame(1, $this->stepUp->required);
    }

    public function testModify_ARefusedSecretIsAuditedAsOneRefusedCallWithFieldNamesOnly(): void
    {
        $this->superadmin = new FakeSuperadminCheck(false);

        $this->modifier()->modify($this->change(testToken: 'sk_test_AAAAAAAAAAbrandnew'));

        self::assertCount(1, $this->audit->rows);
        self::assertStringNotContainsString('brandnew', (string) json_encode($this->audit->rows));
    }

    private function modifier(): StripeConfigModifier
    {
        $config = $this->createStub(ModuleConfigurationServiceInterface::class);

        return new StripeConfigModifier(
            $this->settings,
            new StripeConfigChangeValidator(),
            new ConfigurationValidator($config),
            $this->superadmin,
            $this->stepUp,
            $this->audit,
        );
    }

    private function change(
        ?string $mode = null,
        ?string $captureMode = null,
        ?string $logLevel = null,
        ?string $testPublishableKey = null,
        ?string $livePublishableKey = null,
        ?string $testToken = null,
        ?string $liveToken = null,
        ?string $testPlatformKey = null,
        ?string $webhookSecret = null,
        ?bool $removeByBasketCurrency = null,
    ): StripeConfigChange {
        return new StripeConfigChange(
            expectedVersion: $this->settings->snapshot()->version(),
            mode: $mode,
            captureMode: $captureMode,
            removeByBasketCurrency: $removeByBasketCurrency,
            logLevel: $logLevel,
            testPublishableKey: $testPublishableKey,
            livePublishableKey: $livePublishableKey,
            testToken: $testToken,
            liveToken: $liveToken,
            testPlatformKey: $testPlatformKey,
            webhookSecret: $webhookSecret,
        );
    }
}
