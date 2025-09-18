<?php

namespace GlobalPostShipping\Tariff;

class CartMeasurementCalculator
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $products;

    /**
     * @param array<int, array<string, mixed>> $products
     */
    public function __construct(array $products)
    {
        $this->products = $products;
    }

    public function calculateTotalWeight(): float
    {
        $totalWeight = 0.0;

        foreach ($this->products as $product) {
            $quantity = $this->extractQuantity($product);
            if ($quantity <= 0) {
                continue;
            }

            $weight = $this->extractFloat($product, ['weight']);
            if ($weight <= 0.0) {
                continue;
            }

            $totalWeight += $weight * $quantity;
        }

        return $totalWeight;
    }

    /**
     * @return array{length?: float, width?: float, height?: float}
     */
    public function calculateDimensions(): array
    {
        $dimensions = [];

        $length = $this->calculateMaxDimension(['length', 'depth']);
        if ($length !== null && $length > 0.0) {
            $dimensions['length'] = $length;
        }

        $width = $this->calculateMaxDimension(['width']);
        if ($width !== null && $width > 0.0) {
            $dimensions['width'] = $width;
        }

        $height = $this->calculateStackedHeight();
        if ($height !== null && $height > 0.0) {
            $dimensions['height'] = $height;
        }

        return $dimensions;
    }

    private function calculateMaxDimension(array $keys): ?float
    {
        $max = null;

        foreach ($this->products as $product) {
            $quantity = $this->extractQuantity($product);
            if ($quantity <= 0) {
                continue;
            }

            $value = $this->extractFloat($product, $keys);
            if ($value <= 0.0) {
                continue;
            }

            if ($max === null || $value > $max) {
                $max = $value;
            }
        }

        return $max;
    }

    private function calculateStackedHeight(): ?float
    {
        $total = 0.0;
        $hasHeight = false;

        foreach ($this->products as $product) {
            $quantity = $this->extractQuantity($product);
            if ($quantity <= 0) {
                continue;
            }

            $height = $this->extractFloat($product, ['height']);
            if ($height <= 0.0) {
                continue;
            }

            $total += $height * $quantity;
            $hasHeight = true;
        }

        return $hasHeight ? $total : null;
    }

    private function extractQuantity(array $product): int
    {
        if (isset($product['cart_quantity'])) {
            return (int) $product['cart_quantity'];
        }

        if (isset($product['quantity'])) {
            return (int) $product['quantity'];
        }

        return 0;
    }

    private function extractFloat(array $product, array $keys): float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $product)) {
                continue;
            }

            $value = (float) $product[$key];
            if (!is_finite($value)) {
                continue;
            }

            return $value;
        }

        return 0.0;
    }
}
