<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\Service;

use OxidEsales\Eshop\Application\Model\Article;
use OxidEsales\PaymentComponent\Adapter\ShopAdapterInterface;
use OxidEsales\PaymentComponent\Mcp\Acp\ProductFieldMapperInterface;

class OxidProductFieldMapper implements ProductFieldMapperInterface
{
    public function __construct(
        private readonly ShopAdapterInterface $shopAdapter
    ) {
    }

    public function mapProduct(mixed $product): array
    {
        if (!$product instanceof Article) {
            return [];
        }

        $shopUrl = $this->shopAdapter->getShopUrl();
        $manufacturer = $product->getManufacturer();
        $price = $product->getPrice();

        $titleVal = $product->getFieldData('oxtitle');
        $title = is_scalar($titleVal) ? (string) $titleVal : '';

        $descVal = $product->getFieldData('oxlongdesc');
        $description = is_scalar($descVal) ? strip_tags((string) $descVal) : '';

        $brandName = '';
        if ($manufacturer !== null) {
            $mfTitleVal = $manufacturer->getFieldData('oxtitle');
            $brandName = is_scalar($mfTitleVal) ? (string) $mfTitleVal : '';
        }

        $bruttoPrice = $price->getBruttoPrice();

        return [
            'id' => $product->getId(),
            'title' => $this->truncate($title, 150),
            'description' => $this->truncate($description, 5000),
            'url' => $shopUrl . '?cl=details&anid=' . $product->getId(),
            'brand' => $brandName,
            'price' => $this->formatPrice($bruttoPrice),
            'currency' => $this->shopAdapter->getShopCurrency(),
            'availability' => $this->mapAvailability($product),
            'image_url' => $this->resolveImageUrl($product, $shopUrl),
            'gtin' => $this->getScalarField($product, 'oxean'),
            'mpn' => $this->getScalarField($product, 'oxmpn'),
            'weight' => $product->getWeight() > 0 ? $product->getWeight() : null,
            'group_id' => $product->getParentId() ?: null,
        ];
    }

    public function getFieldNames(): array
    {
        return [
            'id', 'title', 'description', 'url', 'brand', 'price',
            'currency', 'availability', 'image_url', 'gtin', 'mpn',
            'weight', 'group_id',
        ];
    }

    private function mapAvailability(Article $product): string
    {
        $stockVal = $product->getFieldData('oxstock');
        $stock = is_numeric($stockVal) ? (int) $stockVal : 0;
        $stockFlagVal = $product->getFieldData('oxstockflag');
        $stockFlag = is_numeric($stockFlagVal) ? (int) $stockFlagVal : 0;

        if ($stock > 0) {
            return 'in_stock';
        }

        return match ($stockFlag) {
            1 => 'out_of_stock',
            4 => 'backorder',
            default => 'out_of_stock',
        };
    }

    private function formatPrice(float $price): string
    {
        return number_format($price, 2, '.', '');
    }

    private function resolveImageUrl(Article $product, string $shopUrl): string
    {
        $picVal = $product->getFieldData('oxpic1');
        $pic = is_scalar($picVal) ? (string) $picVal : '';
        if ($pic === '') {
            return '';
        }

        if (str_starts_with($pic, 'http')) {
            return $pic;
        }

        return rtrim($shopUrl, '/') . '/out/pictures/master/product/1/' . $pic;
    }

    private function getScalarField(Article $product, string $field): ?string
    {
        $value = $product->getFieldData($field);

        return is_scalar($value) && $value !== '' ? (string) $value : null;
    }

    private function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3) . '...';
    }
}
