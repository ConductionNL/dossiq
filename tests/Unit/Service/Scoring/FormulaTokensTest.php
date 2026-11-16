<?php

/**
 * Unit tests for FormulaTokens: the tokens of the scoring grammar, and what it refuses.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Scoring;

use InvalidArgumentException;
use OCA\Dossiq\Service\Scoring\FormulaTokens;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Scoring\FormulaTokens
 */
final class FormulaTokensTest extends TestCase {

	/**
	 * Numbers, names and operators each get their kind, whitespace between them is dropped.
	 *
	 * @return void
	 */
	public function testEachTokenGetsItsKind(): void {
		$this->assertSame(
			[
				['name', 'max'],
				['op', '('],
				['num', '0'],
				['op', ','],
				['name', 'max_Points2'],
				['op', '*'],
				['num', '1.5'],
				['op', ')'],
				['op', '-'],
				['op', '/'],
				['op', '+'],
			],
			(new FormulaTokens())->split(expression: " max( 0 ,max_Points2*1.5 )\t- / +  ")
		);
	}//end testEachTokenGetsItsKind()

	/**
	 * A character outside the grammar refuses the expression.
	 *
	 * @return void
	 */
	public function testACharacterOutsideTheGrammarIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('scoring_formula_malformed');
		(new FormulaTokens())->split(expression: 'price % 2');
	}//end testACharacterOutsideTheGrammarIsRefused()

	/**
	 * An expression of only whitespace has no tokens and is refused.
	 *
	 * @return void
	 */
	public function testAnExpressionWithoutTokensIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('scoring_formula_malformed');
		(new FormulaTokens())->split(expression: " \n ");
	}//end testAnExpressionWithoutTokensIsRefused()
}//end class
