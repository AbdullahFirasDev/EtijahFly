<?php

use App\Domain\Shared\Money\Money;

it('uses exact decimal arithmetic with half-up rounding', function () {
    expect(Money::of('0.1', 'USD')->plus(Money::of('0.2', 'USD'))->toDecimal())->toBe('0.30')
        ->and(Money::of('100.00', 'USD')->percent('2.5')->toDecimal())->toBe('2.50')
        ->and(Money::of('10.005', 'USD')->toDecimal())->toBe('10.01')
        ->and(Money::of('261.00', 'USD')->percent('5')->toDecimal())->toBe('13.05')
        ->and(Money::of('199.99', 'USD')->multipliedBy('1310')->toDecimal())->toBe('261986.90');
});

it('refuses to mix currencies', function () {
    Money::of('1', 'USD')->plus(Money::of('1', 'IQD'));
})->throws(InvalidArgumentException::class);

it('rejects non numeric input', function () {
    Money::of('12abc', 'USD');
})->throws(InvalidArgumentException::class);

it('compares and serialises', function () {
    $a = Money::of('5', 'IQD');
    $b = Money::of('7.5', 'IQD');

    expect($a->lessThan($b))->toBeTrue()
        ->and($a->max($b)->toDecimal())->toBe('7.50')
        ->and($a->minus($b)->isNegative())->toBeTrue()
        ->and($a->jsonSerialize())->toBe(['amount' => '5.00', 'currency' => 'IQD']);
});
