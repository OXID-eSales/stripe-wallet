<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\ProductFeed;

use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;

class JsonlFeedGenerator implements ProductFeedGeneratorInterface
{
    public function __construct(
        private readonly string $storeCountry = 'DE',
        private readonly string $targetCountries = 'DE,AT,CH'
    ) {
    }

    public function generate(array $products): string
    {
        if (empty($products)) {
            return '';
        }

        $lines = [];
        foreach ($products as $product) {
            $priceVal = isset($product['price']) && is_scalar($product['price'])
                ? (string) $product['price']
                : '0.00';
            $currencyVal = isset($product['currency']) && is_string($product['currency'])
                ? $product['currency']
                : 'EUR';

            $entry = [
                'item_id' => $product['id'] ?? '',
                'title' => $product['title'] ?? '',
                'description' => $product['description'] ?? '',
                'url' => $product['url'] ?? '',
                'brand' => $product['brand'] ?? '',
                'price' => $priceVal . ' ' . $currencyVal,
                'availability' => $product['availability'] ?? 'out_of_stock',
                'image_url' => $product['image_url'] ?? '',
                'target_countries' => $this->targetCountries,
                'store_country' => $this->storeCountry,
                'is_eligible_search' => true,
                'is_eligible_checkout' => ($product['availability'] ?? '') === 'in_stock',
            ];

            if (!empty($product['gtin'])) {
                $entry['gtin'] = $product['gtin'];
            }
            if (!empty($product['group_id'])) {
                $entry['group_id'] = $product['group_id'];
            }

            $lines[] = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        return implode("\n", $lines) . "\n";
    }

    public function getContentType(): string
    {
        return 'application/x-jsonlines; charset=utf-8';
    }

    public function getFileExtension(): string
    {
        return 'jsonl';
    }
}
