<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\AcpProductServiceInterface;

class StripeAcpProductService implements AcpProductServiceInterface
{
    public function __construct(
        private readonly ShopAdapterInterface $shopAdapter
    ) {
    }

    public function listProducts(array $filters = []): array
    {
        return [
            'products' => [],
            'total' => 0,
            'message' => 'Product listing not yet implemented for ' . $this->shopAdapter->getAdapterName(),
        ];
    }

    public function getProduct(string $productId): ?array
    {
        return null;
    }
}
