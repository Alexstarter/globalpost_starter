<?php

require_once __DIR__ . '/../bootstrap.php';

use GlobalPostShipping\Tariff\CartMeasurementCalculator;

/**
 * @param float $expected
 * @param float $actual
 * @param float $delta
 * @param string $message
 */
function assertFloatEquals($expected, $actual, $delta, string $message = ''): void
{
    if (abs($expected - $actual) > $delta) {
        throw new RuntimeException($message !== '' ? $message : sprintf('Failed asserting that %.4f matches %.4f', $expected, $actual));
    }
}

/**
 * @param mixed $expected
 * @param mixed $actual
 * @param string $message
 */
function assertSameValue($expected, $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message !== '' ? $message : 'Failed asserting that values are identical.');
    }
}

/**
 * @param callable $test
 */
function runTest(string $name, callable $test): void
{
    try {
        $test();
        echo '.';
    } catch (Throwable $exception) {
        echo PHP_EOL . 'Test failed: ' . $name . PHP_EOL;
        echo $exception->getMessage() . PHP_EOL;
        exit(1);
    }
}

runTest('it sums cart weight and dimensions from products', function (): void {
    $calculator = new CartMeasurementCalculator([
        [
            'cart_quantity' => 2,
            'weight' => 0.75,
            'depth' => 25.4,
            'width' => 15.2,
            'height' => 4.5,
        ],
        [
            'cart_quantity' => 1,
            'weight' => 1.2,
            'depth' => 32.0,
            'width' => 12.0,
            'height' => 8.0,
        ],
    ]);

    assertFloatEquals(2.7, $calculator->calculateTotalWeight(), 0.0001, 'Weight should sum quantity and product weight.');

    $dimensions = $calculator->calculateDimensions();
    assertFloatEquals(32.0, $dimensions['length'] ?? 0.0, 0.0001, 'Length should be the largest product depth.');
    assertFloatEquals(15.2, $dimensions['width'] ?? 0.0, 0.0001, 'Width should be the largest product width.');
    assertFloatEquals(17.0, $dimensions['height'] ?? 0.0, 0.0001, 'Height should stack product heights by quantity.');
});

runTest('it ignores invalid measurements and quantities', function (): void {
    $calculator = new CartMeasurementCalculator([
        [
            'cart_quantity' => 0,
            'weight' => 10,
            'depth' => 10,
            'width' => 10,
            'height' => 10,
        ],
        [
            'cart_quantity' => 1,
            'weight' => -5,
            'depth' => null,
            'width' => '0',
            'height' => '0',
        ],
    ]);

    assertFloatEquals(0.0, $calculator->calculateTotalWeight(), 0.0001, 'Invalid weight rows should be ignored.');

    $dimensions = $calculator->calculateDimensions();
    assertSameValue([], $dimensions, 'Invalid dimensions should not be included.');
});

runTest('it falls back to quantity key when cart_quantity missing', function (): void {
    $calculator = new CartMeasurementCalculator([
        [
            'quantity' => 3,
            'weight' => 0.2,
            'length' => 18,
            'width' => 6,
            'height' => 3.5,
        ],
    ]);

    assertFloatEquals(0.6, $calculator->calculateTotalWeight(), 0.0001, 'Quantity should fallback to the quantity key.');

    $dimensions = $calculator->calculateDimensions();
    assertFloatEquals(18.0, $dimensions['length'] ?? 0.0, 0.0001, 'Length should use the first available key.');
    assertFloatEquals(6.0, $dimensions['width'] ?? 0.0, 0.0001, 'Width should be extracted when available.');
    assertFloatEquals(10.5, $dimensions['height'] ?? 0.0, 0.0001, 'Height should stack using the quantity.');
});

