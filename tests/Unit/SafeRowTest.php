<?php

use App\Shared\Support\SafeRow;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\NumericCell;
use OpenSpout\Common\Entity\Cell\StringCell;

it('never writes text as a formula', function () {
    $cells = SafeRow::fromValues(['=HYPERLINK("http://x")', 'Lan', 1250, '-5000'])->getCells();
    expect($cells[0])->toBeInstanceOf(StringCell::class)->not->toBeInstanceOf(FormulaCell::class)
        ->and($cells[0]->getValue())->toBe('=HYPERLINK("http://x")')
        ->and($cells[2])->toBeInstanceOf(NumericCell::class);
});

it('prefixes risky text in CSV files, not plain numbers', function () {
    $values = fn (array $v) => array_map(fn ($c) => $c->getValue(), SafeRow::fromValues($v, csv: true)->getCells());
    expect($values(['=1+1', '+84 90', '-5000', '@SUM(A1)', 'Lan', '-12.5']))->toBe(["'=1+1", "'+84 90", '-5000', "'@SUM(A1)", 'Lan', '-12.5']);
});
