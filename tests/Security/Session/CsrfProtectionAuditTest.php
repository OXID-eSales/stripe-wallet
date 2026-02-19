<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\Session;

use PHPUnit\Framework\TestCase;

/**
 * Documents Finding F7: Missing CSRF token validation on payment endpoints.
 *
 * OXID's sess_challenge token should be validated on state-changing operations,
 * but the Stripe payment execution endpoints do not check it.
 *
 * @group security
 * @group pci-dss
 * @group finding-f7
 * @group sprint-58
 */
final class CsrfProtectionAuditTest extends TestCase
{
    /**
     * @test
     *
     * Finding F7: Documents absence of sess_challenge check in StripeOrderController.
     */
    public function testDocumentsNoSessChallengeCheckInStripeController(): void
    {
        $controllerFile = dirname(__DIR__, 3) . '/src/Stripe/Controller/StripeOrderController.php';
        if (!file_exists($controllerFile)) {
            $this->markTestSkipped('StripeOrderController not found');
        }

        $source = file_get_contents($controllerFile);
        $this->assertIsString($source);

        // The controller uses sess_challenge for order tracking (setSessionVariable),
        // but does NOT validate it as a CSRF token on incoming requests.
        $setsSessChallenge = str_contains($source, 'setSessionVariable(\'sess_challenge\'')
            || str_contains($source, "setSessionVariable('sess_challenge'");
        $readsChallenge = str_contains($source, 'getSessionVariable(\'sess_challenge\')');
        $readsRequestParam = str_contains($source, 'getRequestParameter');
        $validatesSessChallenge = str_contains($source, 'getSessionChallengeToken')
            || ($readsChallenge && $readsRequestParam);

        // Document: sets sess_challenge for order flow but does NOT validate it as CSRF input
        $this->assertTrue($setsSessChallenge, 'Controller sets sess_challenge for order tracking');
        $this->assertFalse(
            $validatesSessChallenge,
            'Finding F7: Controller does not validate sess_challenge as CSRF token on input'
        );
    }

    /**
     * @test
     *
     * Finding F7: Documents absence of CSRF check in checkout session creation.
     */
    public function testDocumentsNoSessChallengeCheckInCheckoutSessionHandler(): void
    {
        $handlerFiles = glob(
            dirname(__DIR__, 3) . '/src/Stripe/EventSystem/Handler/*CheckoutSession*.php'
        );

        if (empty($handlerFiles)) {
            $this->markTestSkipped('Checkout session handlers not found');
        }

        foreach ($handlerFiles as $file) {
            $source = file_get_contents($file);
            if ($source === false) {
                continue;
            }

            $hasSessChallenge = str_contains($source, 'sess_challenge');

            // Document: handlers don't check CSRF tokens
            $this->assertFalse(
                $hasSessChallenge,
                "Finding F7: " . basename($file) . " does not validate sess_challenge"
            );
        }
    }

    /**
     * @test
     *
     * Positive case: MCP controllers use bearer token auth, not CSRF.
     * This is correct for API endpoints — CSRF protection is for browser-based forms.
     */
    public function testMcpControllersUseTokenAuthInsteadOfCsrf(): void
    {
        $controllerFile = dirname(__DIR__, 3) . '/src/Stripe/Mcp/Controller/UcpCheckoutController.php';
        if (!file_exists($controllerFile)) {
            $this->markTestSkipped('UcpCheckoutController not found');
        }

        $source = file_get_contents($controllerFile);
        $this->assertIsString($source);

        $this->assertStringContainsString('authGuard', $source);
        $this->assertStringContainsString('authenticate', $source);
    }
}
