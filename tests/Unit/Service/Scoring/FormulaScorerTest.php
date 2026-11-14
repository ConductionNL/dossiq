<?php

/**
 * Unit tests for FormulaScorer: the three common formulas as data, and the closed grammar.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenders-run-on-the-tender-case-type/tasks.md#2-1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Scoring;

use InvalidArgumentException;
use OCA\Dossiq\Service\Scoring\FormulaScorer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Scoring\FormulaScorer
 */
final class FormulaScorerTest extends TestCase {

	/**
	 * Lowest price: the lowest bid gets all points, the others in proportion.
	 */
	private const LOWEST_PRICE = ['slug' => 'lowest-price', 'expression' => 'maxPoints * lowestPrice / price'];

	/**
	 * Relative price formula with a floor at zero.
	 */
	private const RELATIVE_PRICE = ['slug' => 'relative-price', 'expression' => 'max(0, maxPoints * (1 - (price - lowestPrice) / lowestPrice))'];

	/**
	 * Price-quality ratio: weighted quality and price points.
	 */
	private const PRICE_QUALITY = ['slug' => 'price-quality', 'expression' => 'round(qualityWeight * quality + priceWeight * maxPoints * lowestPrice / price, 2)'];

	public function testTheLowestPriceFormulaGivesTheLowestBidAllPoints(): void {
		$scorer = new FormulaScorer();

		$this->assertSame(40.0, $scorer->score(formula: self::LOWEST_PRICE, values: ['maxPoints' => 40, 'lowestPrice' => 1096000, 'price' => 1096000]));
		$this->assertEqualsWithDelta(38.188, $scorer->score(formula: self::LOWEST_PRICE, values: ['maxPoints' => 40, 'lowestPrice' => 1096000, 'price' => 1148000]), 0.001);
	}

	public function testTheRelativePriceFormulaNeverGoesBelowZero(): void {
		$scorer = new FormulaScorer();

		$this->assertEqualsWithDelta(36.0, $scorer->score(formula: self::RELATIVE_PRICE, values: ['maxPoints' => 40, 'lowestPrice' => 1000, 'price' => 1100]), 0.0001);
		$this->assertSame(0.0, $scorer->score(formula: self::RELATIVE_PRICE, values: ['maxPoints' => 40, 'lowestPrice' => 1000, 'price' => 2500]));
	}

	public function testThePriceQualityRatioWeighsBothParts(): void {
		$values = ['qualityWeight' => 0.6, 'quality' => 90, 'priceWeight' => 0.4, 'maxPoints' => 100, 'lowestPrice' => 1096000, 'price' => 1148000];

		$this->assertSame(92.19, (new FormulaScorer())->score(formula: self::PRICE_QUALITY, values: $values));
	}

	public function testANewFormulaIsANewRecordNotNewCode(): void {
		$geometric = ['slug' => 'weighted-geometric', 'expression' => 'abs(-(a * b)) / max(a, b, 1)'];

		$this->assertSame(3.0, (new FormulaScorer())->score(formula: $geometric, values: ['a' => 3, 'b' => 4]));
	}

	public function testPrecedenceParenthesesAndUnaryMinus(): void {
		$scorer = new FormulaScorer();

		$this->assertSame(14.0, $scorer->evaluate(expression: '2 + 3 * 4', values: []));
		$this->assertSame(20.0, $scorer->evaluate(expression: '(2 + 3) * 4', values: []));
		$this->assertSame(-1.0, $scorer->evaluate(expression: '-(3 - 2)', values: []));
		$this->assertSame(1.0, $scorer->evaluate(expression: '6 / 3 / 2', values: []));
		$this->assertSame(3.0, $scorer->evaluate(expression: '10 - 4 - 3', values: []));
		$this->assertSame(2.35, $scorer->evaluate(expression: 'round(2.345, 2)', values: []));
	}

	/**
	 * @return array<string, array{0: string, 1: array<string, mixed>, 2: string}>
	 */
	public static function refusals(): array {
		return [
			'missing value' => ['price * 2', [], 'scoring_formula_missing_value: price'],
			'a non-number value is not usable' => ['price * 2', ['price' => '100'], 'scoring_formula_missing_value: price'],
			'division by zero' => ['maxPoints / price', ['maxPoints' => 40, 'price' => 0], 'scoring_formula_division_by_zero'],
			'unknown function' => ['system(1)', [], 'scoring_formula_unknown_function: system'],
			'wrong argument count' => ['abs(1, 2)', [], 'scoring_formula_wrong_arguments: abs'],
			'code is not arithmetic' => ['phpinfo(); 1', [], 'scoring_formula_malformed'],
			'a quote' => ['"1"', [], 'scoring_formula_malformed'],
			'dangling operator' => ['1 +', [], 'scoring_formula_malformed'],
			'unclosed parenthesis' => ['(1 + 2', [], 'scoring_formula_malformed'],
			'two values side by side' => ['1 2', [], 'scoring_formula_malformed'],
			'empty' => ['   ', [], 'scoring_formula_malformed'],
			'too deep' => [str_repeat('(', 40) . '1' . str_repeat(')', 40), [], 'scoring_formula_too_deep'],
			'too long' => [str_repeat('1+', 300) . '1', [], 'scoring_formula_too_long'],
		];
	}

	/**
	 * @param string               $expression The expression.
	 * @param array<string, mixed> $values     The values.
	 * @param string               $message    The refusal.
	 */
	#[DataProvider('refusals')]
	public function testAnExpressionOutsideTheGrammarIsRefused(string $expression, array $values, string $message): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage($message);

		(new FormulaScorer())->evaluate(expression: $expression, values: $values);
	}

	public function testAFormulaWithoutAnExpressionIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('scoring_formula_without_expression');

		(new FormulaScorer())->score(formula: ['slug' => 'empty'], values: []);
	}

	public function testTheScorerKeepsNoStateBetweenFormulas(): void {
		$scorer = new FormulaScorer();
		$scorer->evaluate(expression: 'a', values: ['a' => 1]);

		$this->expectException(InvalidArgumentException::class);
		$scorer->evaluate(expression: 'a', values: []);
	}
}
