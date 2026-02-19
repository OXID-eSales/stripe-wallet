<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\InputValidation;

use OxidEsales\PaymentComponent\Mcp\Ucp\UcpRequestValidator;
use PHPUnit\Framework\TestCase;

/**
 * F22: UCP Profile URL Not Validated
 *
 * MEDIUM — BSI TR-03116
 *
 * Profile URL from UCP-Agent header extracted via regex but never validated
 * as HTTPS URL. Accepts data:, javascript:, or arbitrary strings.
 *
 * @group security
 * @group f22
 * @since Sprint 61
 */
class UcpProfileUrlValidationTest extends TestCase
{
    private UcpRequestValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new UcpRequestValidator();
    }

    /**
     * F22: JavaScript protocol URL accepted as profile.
     */
    public function testJavascriptProtocolAccepted(): void
    {
        $headers = ['ucp-agent' => 'profile="javascript:alert(document.cookie)"'];

        $profile = $this->validator->extractAgentProfile($headers);

        // VULNERABILITY: javascript: URL accepted
        $this->assertSame(
            'javascript:alert(document.cookie)',
            $profile,
            'F22: javascript: protocol accepted as profile URL'
        );
    }

    /**
     * F22: Data URI accepted as profile.
     */
    public function testDataUriAccepted(): void
    {
        $headers = ['ucp-agent' => 'profile="data:text/html,<script>alert(1)</script>"'];

        $profile = $this->validator->extractAgentProfile($headers);

        $this->assertNotNull($profile);
        $this->assertStringStartsWith('data:', $profile);
    }

    /**
     * F22: HTTP (non-HTTPS) URL accepted.
     */
    public function testHttpUrlAccepted(): void
    {
        $headers = ['ucp-agent' => 'profile="http://insecure.example.com/agent"'];

        $profile = $this->validator->extractAgentProfile($headers);

        // VULNERABILITY: HTTP (not HTTPS) accepted
        $this->assertSame('http://insecure.example.com/agent', $profile);
    }

    /**
     * F22: File protocol URL accepted.
     */
    public function testFileProtocolAccepted(): void
    {
        $headers = ['ucp-agent' => 'profile="file:///etc/passwd"'];

        $profile = $this->validator->extractAgentProfile($headers);

        $this->assertSame('file:///etc/passwd', $profile);
    }

    /**
     * F22: Arbitrary non-URL string accepted.
     */
    public function testArbitraryStringAccepted(): void
    {
        $headers = ['ucp-agent' => 'profile="not-a-url-at-all"'];

        $profile = $this->validator->extractAgentProfile($headers);

        $this->assertSame('not-a-url-at-all', $profile);
    }

    /**
     * F22: SQL injection payload accepted as profile URL.
     */
    public function testSqlInjectionAccepted(): void
    {
        $payload = "https://example.com'; DROP TABLE users--";
        $headers = ['ucp-agent' => 'profile="' . $payload . '"'];

        $profile = $this->validator->extractAgentProfile($headers);

        $this->assertNotNull($profile);
        $this->assertStringContainsString('DROP TABLE', $profile);
    }

    /**
     * F22: No whitelist validation for allowed domains.
     */
    public function testNoWhitelistValidation(): void
    {
        $sourceFile = dirname(__DIR__, 4)
            . '/source/extensions/payment-component/src/Mcp/Ucp/UcpRequestValidator.php';

        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('Source file not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        $this->assertStringNotContainsString('allowedDomains', $source);
        $this->assertStringNotContainsString('whitelist', strtolower($source));
        $this->assertStringNotContainsString('filter_var', $source);
        $this->assertStringNotContainsString('FILTER_VALIDATE_URL', $source);
    }

    /**
     * Positive: Valid HTTPS profile URL accepted.
     */
    public function testValidHttpsUrlAccepted(): void
    {
        $headers = ['ucp-agent' => 'profile="https://agent.example.com/.well-known/ucp"'];

        $profile = $this->validator->extractAgentProfile($headers);

        $this->assertSame('https://agent.example.com/.well-known/ucp', $profile);
    }

    /**
     * Positive: Missing UCP-Agent header returns null.
     */
    public function testMissingUcpAgentReturnsNull(): void
    {
        $profile = $this->validator->extractAgentProfile([]);

        $this->assertNull($profile);
    }

    /**
     * Positive: Empty UCP-Agent header returns null.
     */
    public function testEmptyUcpAgentReturnsNull(): void
    {
        $profile = $this->validator->extractAgentProfile(['ucp-agent' => '']);

        $this->assertNull($profile);
    }

    /**
     * Positive: UCP-Agent without profile= returns null.
     */
    public function testUcpAgentWithoutProfileReturnsNull(): void
    {
        $headers = ['ucp-agent' => 'version="1.0"'];

        $profile = $this->validator->extractAgentProfile($headers);

        $this->assertNull($profile);
    }
}
