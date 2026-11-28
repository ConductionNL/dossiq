<?php

/**
 * AdviceTable unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\DecisionAdvice
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\DecisionAdvice;

use OCA\Dossiq\Service\DecisionAdvice\AdviceTable;
use OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceRefusal;
use PHPUnit\Framework\TestCase;

/**
 * A stored decision table, read for advice on a case.
 *
 * @covers \OCA\Dossiq\Service\DecisionAdvice\AdviceTable
 * @uses   \OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceRefusal
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class AdviceTableTest extends TestCase {

	/**
	 * A table with two inputs, one output and three rules.
	 *
	 * @return AdviceTable
	 */
	private function table(): AdviceTable {
		return new AdviceTable(
			table: [
				'inputs' => [['name' => 'ernst', 'type' => 'string'], ['name' => ' '], ['name' => 'gedrag']],
				'outputs' => [['name' => 'interventie']],
				'rules' => [
					['inputEntries' => ['"licht"', '-', '"bewust"'], 'outputEntries' => ['"waarschuwing"']],
					['inputEntries' => ['"zwaar"', '-', ''], 'outputEntries' => ['"last"']],
					['inputEntries' => ['"licht"', '-', '"bewust"'], 'outputEntries' => ['"waarschuwing"']],
				],
			]
		);
	}//end table()

	/**
	 * The named inputs carry the distinct literal values their column uses; the outcomes those of the first output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testTheFormOffersTheValuesTheRulesUse(): void {
		$this->assertSame(
			expected: [
				['name' => 'ernst', 'type' => 'string', 'options' => ['licht', 'zwaar']],
				['name' => 'gedrag', 'type' => 'string', 'options' => ['bewust']],
			],
			actual: $this->table()->offeredInputs()
		);
		$this->assertSame(expected: ['waarschuwing', 'last'], actual: $this->table()->outcomes());
	}//end testTheFormOffersTheValuesTheRulesUse()

	/**
	 * Every declared input needs a value; the recommendation is the first output's.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testTheValuesAndTheRecommendationAreRead(): void {
		$this->assertSame(
			expected: ['ernst' => 'licht', 'gedrag' => 'bewust'],
			actual: $this->table()->declaredValues(inputs: ['ernst' => ' licht ', 'gedrag' => 'bewust', 'extra' => 'x'])
		);
		$this->assertSame(expected: 'last', actual: $this->table()->recommendation(outputs: ['interventie' => 'last']));

		$this->expectException(exception: DecisionAdviceRefusal::class);
		$this->expectExceptionMessage(message: 'Vul in: gedrag');
		$this->table()->declaredValues(inputs: ['ernst' => 'licht', 'gedrag' => ' ']);
	}//end testTheValuesAndTheRecommendationAreRead()
}//end class
