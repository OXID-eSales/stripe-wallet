<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\GraphQL\Admin\Service;

use OxidEsales\GraphQL\Admin\Audit\DataType\AuditTrail;
use OxidEsales\GraphQL\Admin\Audit\Service\AuditRecorder;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\OxidStripeAdminAudit;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeAdminRefusal;
use OxidEsales\Payments\Stripe\GraphQL\Admin\Service\StripeConfigResult;
use PHPUnit\Framework\TestCase;

final class OxidStripeAdminAuditTest extends TestCase
{
    protected function setUp(): void
    {
        // The real recorder is final: with the admin API installed the audit row is proven by the integration and
        // e2e suites; this unit test runs against the stand-in of the standalone suite.
        if ((new \ReflectionClass(AuditRecorder::class))->isFinal()) {
            self::markTestSkipped('the real AuditRecorder is final; covered by the integration suite');
        }
    }

    public function testAudit_RecordsOneTrailOnTheStripeProviderWithFieldNamesOnly(): void
    {
        $trails = [];
        $recorder = new class ($trails) extends AuditRecorder {
            /** @param list<AuditTrail> $trails */
            public function __construct(public array &$trails)
            {
            }

            public function auditedAdmin(string $operation, \Closure $action): mixed
            {
                $trail = new AuditTrail($operation);
                $result = $action($trail);
                $this->trails[] = $trail;

                return $result;
            }
        };

        $result = (new OxidStripeAdminAudit($recorder))->audited(
            'adminStripeConfigModify',
            ['captureMode', 'testToken'],
            static fn (): StripeConfigResult => StripeConfigResult::refused(StripeAdminRefusal::invalid('captureMode', 'not_allowed', 'not allowed')),
        );

        self::assertSame('not_allowed', $result->refusal()?->code);
        self::assertCount(1, $trails);
        self::assertSame('adminStripeConfigModify', $trails[0]->operation);
        self::assertSame('paymentProvider', $trails[0]->entityType);
        self::assertSame('stripe', $trails[0]->entityId);
        self::assertSame(['captureMode', 'testToken'], $trails[0]->fields);
        self::assertSame([], $trails[0]->values);
    }

    public function testAudit_WithoutTheAdminApi_FailsClosed(): void
    {
        $this->expectException(\LogicException::class);

        (new OxidStripeAdminAudit(null))->audited('adminStripeConfigModify', [], static fn (): StripeConfigResult => throw new \RuntimeException('must not run'));
    }
}
