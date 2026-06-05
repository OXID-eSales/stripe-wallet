<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Admin;

use OxidEsales\PaymentBase\Adapter\SessionAdapterInterface;
use OxidEsales\PaymentBase\Validation\FieldValidationResult;
use OxidEsales\Payments\Stripe\Admin\AdminValidationFeedback;
use OxidEsales\Payments\Stripe\Admin\AdminValidationFeedbackInterface;
use OxidEsales\Payments\Stripe\Service\FieldValidationFailure;
use PHPUnit\Framework\TestCase;

/**
 * Sprint 120 Phase B (STRP-129): session-backed feedback channel for admin
 * Payment-tab validation failures. handleAction() is void and the tab
 * re-renders after the POST, so failures must survive exactly one render
 * cycle: store() on the gate path, consume() (read-and-clear) on render.
 *
 * The session payload is plain arrays (field/code/char/action) — never
 * serialized value objects.
 *
 * @covers \OxidEsales\Payments\Stripe\Admin\AdminValidationFeedback
 * @group sprint-120
 */
final class AdminValidationFeedbackTest extends TestCase
{
    /** In-memory stand-in for the OXID session. @var array<string, mixed> */
    private array $sessionStore = [];

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(
            AdminValidationFeedbackInterface::class,
            new AdminValidationFeedback($this->createSessionStub())
        );
    }

    public function testStoreWritesPlainArraysUnderOrderScopedKey(): void
    {
        $sut = new AdminValidationFeedback($this->createSessionStub());

        $sut->store('order-1', 'capture', [$this->captureReasonFailure('<')]);

        $this->assertArrayHasKey('stripe_admin_validation_order-1', $this->sessionStore);
        $this->assertSame(
            [
                [
                    'field'  => 'captureReason',
                    'code'   => FieldValidationResult::CODE_BLOCKED_CHARACTER,
                    'char'   => '<',
                    'action' => 'capture',
                ],
            ],
            $this->sessionStore['stripe_admin_validation_order-1']
        );
    }

    public function testConsumeReturnsStoredEntriesAndClearsTheVariable(): void
    {
        $sut = new AdminValidationFeedback($this->createSessionStub());
        $sut->store('order-1', 'capture', [$this->captureReasonFailure('{')]);

        $entries = $sut->consume('order-1');

        $this->assertCount(1, $entries);
        $this->assertSame('captureReason', $entries[0]['field']);
        $this->assertSame('{', $entries[0]['char']);
        $this->assertNull($this->sessionStore['stripe_admin_validation_order-1']);
    }

    public function testConsumeOnEmptySessionReturnsEmptyList(): void
    {
        $sut = new AdminValidationFeedback($this->createSessionStub());

        $this->assertSame([], $sut->consume('order-1'));
    }

    public function testTwoOrderIdsDoNotCrossTalk(): void
    {
        $sut = new AdminValidationFeedback($this->createSessionStub());
        $sut->store('order-1', 'capture', [$this->captureReasonFailure('<')]);

        $this->assertSame([], $sut->consume('order-2'));
        $this->assertCount(1, $sut->consume('order-1'), 'order-1 entry untouched by order-2 read');
    }

    public function testConsumeTwiceReturnsEmptyOnSecondCall(): void
    {
        $sut = new AdminValidationFeedback($this->createSessionStub());
        $sut->store('order-1', 'capture', [$this->captureReasonFailure('<')]);

        $sut->consume('order-1');

        $this->assertSame([], $sut->consume('order-1'), 'read-once: no stale error on next render');
    }

    public function testMalformedSessionPayloadYieldsEmptyListWithoutThrowing(): void
    {
        $this->sessionStore['stripe_admin_validation_order-1'] = 'not-an-array';
        $sut = new AdminValidationFeedback($this->createSessionStub());

        $this->assertSame([], $sut->consume('order-1'));
    }

    // ---------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------

    private function captureReasonFailure(string $char): FieldValidationFailure
    {
        return new FieldValidationFailure(
            field: 'captureReason',
            addressKind: 'admin',
            code: FieldValidationResult::CODE_BLOCKED_CHARACTER,
            offendingChar: $char,
            oxidColumn: null,
        );
    }

    private function createSessionStub(): SessionAdapterInterface
    {
        $session = $this->createMock(SessionAdapterInterface::class);

        $session->method('setVariable')
            ->willReturnCallback(function (string $name, mixed $value): void {
                $this->sessionStore[$name] = $value;
            });

        $session->method('getVariable')
            ->willReturnCallback(function (string $name): mixed {
                return $this->sessionStore[$name] ?? null;
            });

        return $session;
    }
}
