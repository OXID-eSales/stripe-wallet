<?php

/**
 * Copyright © OXID eSales AG. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Tests\Unit\Stripe\Service;

use OxidEsales\PaymentComponent\Contract\PaymentCustomer;
use OxidEsales\PaymentComponent\Repository\PaymentCustomerRepositoryInterface;
use OxidEsales\Payments\Stripe\Adapter\StripeAdapterInterface;
use OxidEsales\Payments\Stripe\Service\Factory\StripeAdapterFactoryInterface;
use OxidEsales\Payments\Stripe\Service\StripeCustomerService;
use OxidEsales\Payments\Stripe\Service\StripeCustomerServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Stripe\Customer;

/**
 * Unit tests for StripeCustomerService.
 *
 * Sprint 45: Stripe Customer lifecycle.
 *
 * @covers \OxidEsales\Payments\Stripe\Service\StripeCustomerService
 * @group sprint-45
 */
class StripeCustomerServiceTest extends TestCase
{
    private PaymentCustomerRepositoryInterface&MockObject $customerRepo;
    private StripeAdapterFactoryInterface&MockObject $adapterFactory;
    private StripeAdapterInterface&MockObject $adapter;
    private StripeCustomerService $service;

    protected function setUp(): void
    {
        $this->customerRepo = $this->createMock(PaymentCustomerRepositoryInterface::class);
        $this->adapterFactory = $this->createMock(StripeAdapterFactoryInterface::class);
        $this->adapter = $this->createMock(StripeAdapterInterface::class);

        $this->adapterFactory
            ->method('getStripeAdapter')
            ->willReturn($this->adapter);

        $this->service = new StripeCustomerService(
            $this->customerRepo,
            $this->adapterFactory
        );
    }

    public function testImplementsInterface(): void
    {
        $this->assertInstanceOf(StripeCustomerServiceInterface::class, $this->service);
    }

    public function testReturnsExistingCustomerId(): void
    {
        $existing = $this->createExistingCustomer('cus_existing_123');

        $this->customerRepo
            ->expects($this->once())
            ->method('findByUserId')
            ->with('user_abc')
            ->willReturn($existing);

        $this->adapter
            ->expects($this->never())
            ->method('createStripeCustomer');

        $this->customerRepo
            ->expects($this->never())
            ->method('save');

        $result = $this->service->resolveStripeCustomerId('user_abc', 'test@example.com', 'Test User');

        $this->assertSame('cus_existing_123', $result);
    }

    public function testCreatesNewCustomerWhenNotFound(): void
    {
        $this->customerRepo
            ->expects($this->once())
            ->method('findByUserId')
            ->with('user_new')
            ->willReturn(null);

        $stripeCustomer = $this->createStripeCustomerObject('cus_new_456');

        $this->adapter
            ->expects($this->once())
            ->method('createStripeCustomer')
            ->with([
                'email' => 'new@example.com',
                'name' => 'New User',
                'metadata' => ['oxid_user_id' => 'user_new'],
            ])
            ->willReturn($stripeCustomer);

        $this->customerRepo
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PaymentCustomer $customer) {
                return $customer->getPaymentCustomerId() === 'cus_new_456'
                    && $customer->getUserId() === 'user_new';
            }));

        $result = $this->service->resolveStripeCustomerId('user_new', 'new@example.com', 'New User');

        $this->assertSame('cus_new_456', $result);
    }

    public function testUpdatesExistingRecordWithoutPaymentCustomerId(): void
    {
        $existing = $this->createExistingCustomer(null);

        $this->customerRepo
            ->expects($this->once())
            ->method('findByUserId')
            ->with('user_abc')
            ->willReturn($existing);

        $stripeCustomer = $this->createStripeCustomerObject('cus_updated_789');

        $this->adapter
            ->expects($this->once())
            ->method('createStripeCustomer')
            ->willReturn($stripeCustomer);

        $this->customerRepo
            ->expects($this->once())
            ->method('save')
            ->with($this->callback(function (PaymentCustomer $customer) {
                return $customer->getPaymentCustomerId() === 'cus_updated_789'
                    && $customer->getId() === 'existing_id';
            }));

        $result = $this->service->resolveStripeCustomerId('user_abc', 'test@example.com', 'Test User');

        $this->assertSame('cus_updated_789', $result);
    }

    public function testPassesCorrectParamsToStripeApi(): void
    {
        $this->customerRepo
            ->method('findByUserId')
            ->willReturn(null);

        $stripeCustomer = $this->createStripeCustomerObject('cus_test');

        $capturedParams = null;
        $this->adapter
            ->expects($this->once())
            ->method('createStripeCustomer')
            ->willReturnCallback(function (array $params) use (&$capturedParams, $stripeCustomer) {
                $capturedParams = $params;
                return $stripeCustomer;
            });

        $this->customerRepo->method('save');

        $this->service->resolveStripeCustomerId('user_xyz', 'john@example.com', 'John Doe');

        $this->assertNotNull($capturedParams);
        $this->assertSame('john@example.com', $capturedParams['email']);
        $this->assertSame('John Doe', $capturedParams['name']);
        $this->assertSame('user_xyz', $capturedParams['metadata']['oxid_user_id']);
    }

    private function createExistingCustomer(?string $paymentCustomerId): PaymentCustomer
    {
        $customer = new PaymentCustomer(
            'existing_id',
            'user_abc',
            new \DateTimeImmutable(),
            new \DateTimeImmutable()
        );
        if ($paymentCustomerId !== null) {
            $customer->setPaymentCustomerId($paymentCustomerId);
        }
        return $customer;
    }

    private function createStripeCustomerObject(string $id): Customer
    {
        return Customer::constructFrom(['id' => $id]);
    }
}
