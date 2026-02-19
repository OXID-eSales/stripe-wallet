<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Security\InputValidation;

use OxidEsales\PaymentComponent\Mcp\AgentContextInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpCheckoutServiceInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\Tool\CreateCheckoutTool;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * F24: CreateCheckoutTool JSON Schema Not Enforced at Runtime
 *
 * MEDIUM — PCI DSS 6.5.1
 *
 * JSON schema defines email format and required address fields, but validation
 * is decorative — no runtime enforcement before passing to AcpCheckoutServiceInterface.
 *
 * @group security
 * @group f24
 * @since Sprint 61
 */
class CheckoutToolSchemaEnforcementTest extends TestCase
{
    private AcpCheckoutServiceInterface&MockObject $checkoutService;
    private AgentContextInterface&MockObject $agentContext;
    private CreateCheckoutTool $tool;

    protected function setUp(): void
    {
        $this->checkoutService = $this->createMock(AcpCheckoutServiceInterface::class);
        $this->agentContext = $this->createMock(AgentContextInterface::class);
        $this->tool = new CreateCheckoutTool($this->checkoutService);
    }

    /**
     * F24: Schema declares email as required with format validation.
     */
    public function testSchemaDeclaresBuyerEmailRequired(): void
    {
        $schema = $this->tool->getInputSchema();

        $this->assertArrayHasKey('properties', $schema);

        $properties = $schema['properties'];
        $this->assertIsArray($properties);
        $this->assertArrayHasKey('buyer', $properties);

        $buyer = $properties['buyer'];
        $this->assertIsArray($buyer);
        $this->assertArrayHasKey('properties', $buyer);

        $buyerProps = $buyer['properties'];
        $this->assertIsArray($buyerProps);
        $this->assertArrayHasKey('email', $buyerProps);

        $email = $buyerProps['email'];
        $this->assertIsArray($email);

        // Schema says format: email
        $this->assertSame('email', $email['format'] ?? null);

        // Schema says buyer.email is required
        $required = $buyer['required'] ?? [];
        $this->assertIsArray($required);
        $this->assertContains('email', $required);
    }

    /**
     * F24: Execute passes arguments directly without validation.
     */
    public function testExecutePassesArgumentsWithoutValidation(): void
    {
        $invalidArgs = [
            'items' => 'not-an-array',
            'buyer' => ['email' => 'not-an-email'],
        ];

        $this->checkoutService
            ->expects($this->once())
            ->method('createCheckout')
            ->with($invalidArgs, $this->agentContext)
            ->willReturn(['status' => 'ok']);

        // VULNERABILITY: Arguments pass directly to service without validation
        $result = $this->tool->execute($invalidArgs, $this->agentContext);

        $this->assertSame(['status' => 'ok'], $result);
    }

    /**
     * F24: Missing required fields pass through to service.
     */
    public function testMissingRequiredFieldsPassThrough(): void
    {
        $incompleteArgs = [];

        $this->checkoutService
            ->expects($this->once())
            ->method('createCheckout')
            ->with($incompleteArgs, $this->agentContext);

        // VULNERABILITY: Empty arguments not rejected
        $this->tool->execute($incompleteArgs, $this->agentContext);
    }

    /**
     * F24: XSS payload in email passes through.
     */
    public function testXssEmailPassesThrough(): void
    {
        $args = [
            'items' => [['id' => 'art1', 'quantity' => 1]],
            'buyer' => ['email' => '<script>alert(1)</script>'],
        ];

        $this->checkoutService
            ->expects($this->once())
            ->method('createCheckout')
            ->with(
                $this->callback(function (array $a): bool {
                    $buyer = $a['buyer'] ?? [];
                    if (!is_array($buyer)) {
                        return false;
                    }
                    return $buyer['email'] === '<script>alert(1)</script>';
                }),
                $this->agentContext
            );

        $this->tool->execute($args, $this->agentContext);
    }

    /**
     * F24: Negative quantity passes through despite schema minimum: 1.
     */
    public function testNegativeQuantityPassesThrough(): void
    {
        $args = [
            'items' => [['id' => 'art1', 'quantity' => -5]],
            'buyer' => ['email' => 'test@example.com'],
        ];

        $this->checkoutService
            ->expects($this->once())
            ->method('createCheckout')
            ->with(
                $this->callback(function (array $a): bool {
                    $items = $a['items'] ?? [];
                    if (!is_array($items)) {
                        return false;
                    }
                    $firstItem = $items[0] ?? [];
                    if (!is_array($firstItem)) {
                        return false;
                    }
                    return $firstItem['quantity'] === -5;
                }),
                $this->agentContext
            );

        $this->tool->execute($args, $this->agentContext);
    }

    /**
     * F24: Extra unexpected fields pass through.
     */
    public function testExtraFieldsPassThrough(): void
    {
        $args = [
            'items' => [['id' => 'art1', 'quantity' => 1]],
            'buyer' => ['email' => 'test@example.com'],
            'admin_override' => true,
            'discount_percent' => 100,
        ];

        $this->checkoutService
            ->expects($this->once())
            ->method('createCheckout')
            ->with(
                $this->callback(function (array $a): bool {
                    return isset($a['admin_override']) && isset($a['discount_percent']);
                }),
                $this->agentContext
            );

        $this->tool->execute($args, $this->agentContext);
    }

    /**
     * F24: No validation code exists in execute() method.
     */
    public function testNoValidationInExecuteMethod(): void
    {
        $sourceFile = dirname(__DIR__, 4)
            . '/source/extensions/payment-component/src/Mcp/Acp/Tool/CreateCheckoutTool.php';

        if (!file_exists($sourceFile)) {
            $this->markTestSkipped('Source file not found');
        }

        $source = file_get_contents($sourceFile);
        $this->assertIsString($source);

        // No validation in execute()
        $this->assertStringNotContainsString('validate', strtolower($source));
        $this->assertStringNotContainsString('filter_var', $source);
        $this->assertStringNotContainsString('preg_match', $source);
        $this->assertStringNotContainsString('json_validate', $source);
    }

    /**
     * Positive: Tool name and description are correct.
     */
    public function testToolNameAndDescription(): void
    {
        $this->assertSame('create_checkout', $this->tool->getName());
        $this->assertNotEmpty($this->tool->getDescription());
    }

    /**
     * Positive: Schema declares items and buyer as required.
     */
    public function testSchemaDeclaresRequiredTopLevelFields(): void
    {
        $schema = $this->tool->getInputSchema();

        $required = $schema['required'] ?? [];
        $this->assertIsArray($required);
        $this->assertContains('items', $required);
        $this->assertContains('buyer', $required);
    }
}
