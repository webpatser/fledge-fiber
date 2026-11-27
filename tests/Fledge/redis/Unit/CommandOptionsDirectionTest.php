<?php

use Fledge\Async\Redis\Command\Option\RangeOptions;
use Fledge\Async\Redis\Command\Option\SortOptions;

it('keeps the bool sort order API and sends DESC only for descending', function () {
    $options = new SortOptions;

    expect($options->isAscending())->toBeTrue()
        ->and($options->getDirection())->toBe(SortDirection::Ascending)
        ->and($options->toQuery())->toBe([]);

    $descending = $options->withDescendingOrder();

    expect($descending->isDescending())->toBeTrue()
        ->and($descending->isAscending())->toBeFalse()
        ->and($descending->getDirection())->toBe(SortDirection::Descending)
        ->and($descending->toQuery())->toBe(['DESC'])
        ->and($descending->withAscendingOrder()->toQuery())->toBe([]);
});

it('accepts a SortDirection or an asc/desc string for sort options', function (SortDirection|string $direction, array $query) {
    $options = (new SortOptions)->withDirection($direction)->withLexicographicSorting();

    expect($options->toQuery())->toBe($query);
})->with([
    'enum ascending' => [SortDirection::Ascending, ['ALPHA']],
    'enum descending' => [SortDirection::Descending, ['DESC', 'ALPHA']],
    'string asc' => ['asc', ['ALPHA']],
    'string DESC' => ['DESC', ['DESC', 'ALPHA']],
]);

it('accepts a SortDirection or an asc/desc string for range options', function (SortDirection|string $direction, array $query) {
    $options = (new RangeOptions)->withDirection($direction)->withLimit(0, 10);

    expect($options->toQuery())->toBe($query);
})->with([
    'enum ascending' => [SortDirection::Ascending, ['LIMIT', 0, 10]],
    'enum descending' => [SortDirection::Descending, ['REV', 'LIMIT', 0, 10]],
    'string asc' => ['ASC', ['LIMIT', 0, 10]],
    'string desc' => ['desc', ['REV', 'LIMIT', 0, 10]],
]);

it('keeps withReverseOrder sending REV', function () {
    $options = (new RangeOptions)->withReverseOrder();

    expect($options->getDirection())->toBe(SortDirection::Descending)
        ->and($options->toQuery())->toBe(['REV']);
});

it('rejects an unknown direction string', function (string $class) {
    (new $class)->withDirection('sideways');
})->with([SortOptions::class, RangeOptions::class])->throws(InvalidArgumentException::class);
