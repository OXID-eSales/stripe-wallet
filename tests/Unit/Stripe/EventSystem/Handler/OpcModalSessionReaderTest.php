<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\EventSystem\Handler;

use OxidEsales\Payments\Stripe\EventSystem\Handler\OpcModalSessionReader;
use PHPUnit\Framework\TestCase;

/**
 * D8: OpcModalSessionReader centralises the `oe_opc_modal_session` key and
 * the modal-ID resolution logic shared by OpcModalSuccessUrlHandler and
 * OpcModalCancelUrlHandler.
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\OxidEsales\Payments\Stripe\EventSystem\Handler\OpcModalSessionReader::class)]
class OpcModalSessionReaderTest extends TestCase
{
    // ==========================================
    // SESSION_KEY constant
    // ==========================================

    public function testSessionKeyConstantHasCorrectValue(): void
    {
        $this->assertSame('oe_opc_modal_session', OpcModalSessionReader::SESSION_KEY);
    }

    // ==========================================
    // getModalId() — request param path
    // ==========================================

    public function testGetModalIdReturnsRequestParamWhenPresent(): void
    {
        $reader = $this->makeReader(requestParam: 'modal_from_request', sessionData: null);

        $this->assertSame('modal_from_request', $reader->getModalId());
    }

    public function testGetModalIdIgnoresEmptyRequestParam(): void
    {
        $reader = $this->makeReader(requestParam: '', sessionData: ['modalId' => 'session_modal']);

        $this->assertSame('session_modal', $reader->getModalId());
    }

    // ==========================================
    // getModalId() — session path
    // ==========================================

    public function testGetModalIdReturnsSessionModalIdWhenNoRequestParam(): void
    {
        $reader = $this->makeReader(requestParam: null, sessionData: ['modalId' => 'modal_abc']);

        $this->assertSame('modal_abc', $reader->getModalId());
    }

    public function testGetModalIdReturnsNullWhenSessionDataIsNull(): void
    {
        $reader = $this->makeReader(requestParam: null, sessionData: null);

        $this->assertNull($reader->getModalId());
    }

    public function testGetModalIdReturnsNullWhenSessionDataIsNotArray(): void
    {
        $reader = $this->makeReader(requestParam: null, sessionData: 'not_an_array');

        $this->assertNull($reader->getModalId());
    }

    public function testGetModalIdReturnsNullWhenModalIdMissingFromSession(): void
    {
        $reader = $this->makeReader(requestParam: null, sessionData: ['originUrl' => 'https://shop.test/']);

        $this->assertNull($reader->getModalId());
    }

    public function testGetModalIdReturnsNullWhenModalIdIsNotString(): void
    {
        $reader = $this->makeReader(requestParam: null, sessionData: ['modalId' => 42]);

        $this->assertNull($reader->getModalId());
    }

    public function testGetModalIdReturnsNullWhenSessionThrows(): void
    {
        $reader = $this->makeReaderWithSessionException();

        $this->assertNull($reader->getModalId());
    }

    // ==========================================
    // getOriginUrl()
    // ==========================================

    public function testGetOriginUrlReturnsUrlFromSession(): void
    {
        $reader = $this->makeReader(
            requestParam: null,
            sessionData: ['modalId' => 'modal_xyz', 'originUrl' => 'https://shop.test/product/']
        );

        $this->assertSame('https://shop.test/product/', $reader->getOriginUrl());
    }

    public function testGetOriginUrlReturnsNullWhenMissing(): void
    {
        $reader = $this->makeReader(requestParam: null, sessionData: ['modalId' => 'modal_xyz']);

        $this->assertNull($reader->getOriginUrl());
    }

    public function testGetOriginUrlReturnsNullWhenSessionThrows(): void
    {
        $reader = $this->makeReaderWithSessionException();

        $this->assertNull($reader->getOriginUrl());
    }

    // ==========================================
    // Helpers
    // ==========================================

    /**
     * @param mixed $sessionData
     */
    private function makeReader(
        ?string $requestParam,
        mixed $sessionData,
    ): OpcModalSessionReader {
        return new class ($requestParam, $sessionData) extends OpcModalSessionReader {
            public function __construct(
                private readonly ?string $testRequestParam,
                private readonly mixed $testSessionData,
            ) {
            }

            protected function getRequestParam(): ?string
            {
                return $this->testRequestParam;
            }

            protected function readSessionVariable(): mixed
            {
                return $this->testSessionData;
            }
        };
    }

    private function makeReaderWithSessionException(): OpcModalSessionReader
    {
        return new class extends OpcModalSessionReader {
            protected function getRequestParam(): ?string
            {
                return null;
            }

            protected function readSessionVariable(): mixed
            {
                throw new \RuntimeException('Session not available');
            }
        };
    }
}
