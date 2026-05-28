<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Controller;

use OxidEsales\Eshop\Core\Registry;
use OxidEsales\Eshop\Core\Session;
use OxidEsales\PaymentBase\Controller\SessionWriterInterface;
use OxidEsales\Payments\Stripe\Controller\OxidSessionWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for OxidSessionWriter.
 *
 * Sprint 114.13 (§8): covers the CSRF-relevant write path.
 * Uses Registry::set() to inject a Session mock, matching the same
 * pattern used by OxidLanguageResolverTest.
 *
 * @covers \OxidEsales\Payments\Stripe\Controller\OxidSessionWriter
 * @group sprint-114-13
 */
final class OxidSessionWriterTest extends TestCase
{
    private Session&MockObject $session;

    protected function setUp(): void
    {
        $this->session = $this->createMock(Session::class);
        Registry::set(Session::class, $this->session);
    }

    protected function tearDown(): void
    {
        Registry::set(Session::class, null);
        parent::tearDown();
    }

    public function testImplementsInterface(): void
    {
        self::assertInstanceOf(SessionWriterInterface::class, new OxidSessionWriter());
    }

    public function testWriteSessChallengeCallsSetVariableWithCorrectKey(): void
    {
        $this->session->expects($this->once())
            ->method('setVariable')
            ->with('sess_challenge', 'order-abc-123');

        (new OxidSessionWriter())->writeSessChallenge('order-abc-123');
    }

    public function testWriteSessChallengeForwardsOrderIdUnchanged(): void
    {
        $capturedValue = null;
        $this->session->method('setVariable')
            ->willReturnCallback(function (string $key, mixed $value) use (&$capturedValue): void {
                $capturedValue = $value;
            });

        (new OxidSessionWriter())->writeSessChallenge('oxid-order-id-xyz');

        self::assertSame('oxid-order-id-xyz', $capturedValue);
    }

    public function testWriteSessChallengeAcceptsEmptyOrderId(): void
    {
        $this->session->expects($this->once())
            ->method('setVariable')
            ->with('sess_challenge', '');

        (new OxidSessionWriter())->writeSessChallenge('');
    }
}
