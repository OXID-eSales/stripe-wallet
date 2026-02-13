<?php

declare(strict_types=1);

namespace OxidEsales\Payments\Stripe\Mcp\ProductFeed;

use OxidEsales\PaymentComponent\Mcp\Acp\ProductFeedGeneratorInterface;

class CsvFeedGenerator implements ProductFeedGeneratorInterface
{
    private const STRIPE_FIELD_MAP = [
        'id' => 'ID',
        'title' => 'Title',
        'description' => 'Description',
        'url' => 'Link',
        'brand' => 'Brand',
        'price' => 'Price',
        'availability' => 'Availability',
        'image_url' => 'image_link',
        'gtin' => 'GTIN',
        'mpn' => 'MPN',
        'group_id' => 'item_group_id',
    ];

    public function generate(array $products): string
    {
        $output = fopen('php://memory', 'r+');
        if ($output === false) {
            return '';
        }

        fputcsv($output, array_values(self::STRIPE_FIELD_MAP));

        foreach ($products as $product) {
            $row = [];
            foreach (array_keys(self::STRIPE_FIELD_MAP) as $internalField) {
                $value = $product[$internalField] ?? '';
                $row[] = is_scalar($value) ? (string) $value : '';
            }
            fputcsv($output, $row);
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return $csv !== false ? $csv : '';
    }

    public function getContentType(): string
    {
        return 'text/csv; charset=utf-8';
    }

    public function getFileExtension(): string
    {
        return 'csv';
    }
}
