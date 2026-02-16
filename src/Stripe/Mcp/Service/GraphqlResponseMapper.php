<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

class GraphqlResponseMapper
{
    /**
     * Map a GraphQL products list response to ACP format.
     *
     * @param array<string, mixed> $data GraphQL response data
     * @param array<string, mixed> $filters Original request filters
     * @return array<string, mixed> ACP product list
     */
    public function mapProductListResponse(array $data, array $filters): array
    {
        $graphqlProducts = $data['products'] ?? [];
        if (!is_array($graphqlProducts)) {
            return $this->emptyResult($filters);
        }

        $products = [];
        foreach ($graphqlProducts as $item) {
            if (!is_array($item)) {
                continue;
            }
            /** @var array<string, mixed> $item */
            $products[] = $this->mapProduct($item);
        }

        $limit = min(is_numeric($filters['limit'] ?? null) ? (int) $filters['limit'] : 20, 100);
        $offset = max(is_numeric($filters['offset'] ?? null) ? (int) $filters['offset'] : 0, 0);

        return [
            'products' => $products,
            'total' => count($products),
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Map a single GraphQL product response to ACP format.
     *
     * @param array<string, mixed> $data GraphQL response data
     * @return array<string, mixed>|null ACP product or null
     */
    public function mapSingleProductResponse(array $data): ?array
    {
        $product = $data['product'] ?? null;
        if (!is_array($product)) {
            return null;
        }

        /** @var array<string, mixed> $product */
        return $this->mapProduct($product);
    }

    /**
     * @param array<string, mixed> $product GraphQL product node
     * @return array<string, mixed> ACP product
     */
    private function mapProduct(array $product): array
    {
        $seoUrl = $this->extractSeoUrl($product);

        return [
            'id' => $product['id'] ?? '',
            'title' => $this->truncate($this->extractString($product, 'title'), 150),
            'description' => $this->truncate(
                strip_tags($this->extractDescription($product)),
                5000
            ),
            'url' => $seoUrl,
            'brand' => $this->extractNestedString($product, 'manufacturer', 'title'),
            'price' => $this->extractFormattedPrice($product),
            'currency' => $this->extractCurrency($product),
            'availability' => $this->mapAvailability($product),
            'image_url' => $this->extractPrimaryImage($product),
            'gtin' => null,
            'mpn' => null,
            'weight' => null,
            'group_id' => null,
            'rating' => is_numeric($product['rating'] ?? null) ? (float) $product['rating'] : null,
            'category' => $this->extractNullableNestedString($product, 'category', 'title'),
            'seo_url' => $seoUrl !== '' ? $seoUrl : null,
        ];
    }

    /**
     * @param array<string, mixed> $product
     */
    private function extractDescription(array $product): string
    {
        $descRaw = $product['longDescription'] ?? $product['shortDescription'] ?? '';

        return is_string($descRaw) ? $descRaw : '';
    }

    /**
     * @param array<string, mixed> $product
     */
    private function extractFormattedPrice(array $product): string
    {
        $price = is_array($product['price'] ?? null) ? $product['price'] : [];
        $bruttoPrice = is_numeric($price['price'] ?? null) ? (float) $price['price'] : 0.0;

        return number_format($bruttoPrice, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $product
     */
    private function extractCurrency(array $product): string
    {
        $price = is_array($product['price'] ?? null) ? $product['price'] : [];
        $currency = is_array($price['currency'] ?? null) ? $price['currency'] : [];

        return is_string($currency['name'] ?? null) ? $currency['name'] : 'EUR';
    }

    /**
     * @param array<string, mixed> $product
     */
    private function extractSeoUrl(array $product): string
    {
        $seo = is_array($product['seo'] ?? null) ? $product['seo'] : [];

        return is_string($seo['url'] ?? null) ? $seo['url'] : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractString(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return is_string($value) ? $value : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractNestedString(array $data, string $parentKey, string $childKey): string
    {
        $parent = is_array($data[$parentKey] ?? null) ? $data[$parentKey] : [];

        return is_string($parent[$childKey] ?? null) ? $parent[$childKey] : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractNullableNestedString(array $data, string $parentKey, string $childKey): ?string
    {
        $parent = is_array($data[$parentKey] ?? null) ? $data[$parentKey] : [];
        $value = $parent[$childKey] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $product
     */
    private function mapAvailability(array $product): string
    {
        $stock = is_numeric($product['stock'] ?? null) ? (int) $product['stock'] : 0;

        if ($stock > 0) {
            return 'in_stock';
        }

        return 'out_of_stock';
    }

    /**
     * @param array<string, mixed> $product
     */
    private function extractPrimaryImage(array $product): string
    {
        $galleryRaw = $product['imageGallery'] ?? null;
        /** @var array<string, mixed> $gallery */
        $gallery = is_array($galleryRaw) ? $galleryRaw : [];

        $images = $gallery['images'] ?? [];
        if (is_array($images) && isset($images[0]) && is_array($images[0])) {
            $firstImage = $images[0];
            if (isset($firstImage['image']) && is_string($firstImage['image'])) {
                return $firstImage['image'];
            }
        }

        if (is_string($gallery['thumb'] ?? null) && $gallery['thumb'] !== '') {
            return $gallery['thumb'];
        }

        return is_string($gallery['icon'] ?? null) ? $gallery['icon'] : '';
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3) . '...';
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function emptyResult(array $filters): array
    {
        $limit = min(is_numeric($filters['limit'] ?? null) ? (int) $filters['limit'] : 20, 100);
        $offset = max(is_numeric($filters['offset'] ?? null) ? (int) $filters['offset'] : 0, 0);

        return [
            'products' => [],
            'total' => 0,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }
}
