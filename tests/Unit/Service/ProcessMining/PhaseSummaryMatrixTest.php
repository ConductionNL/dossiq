<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\ProcessMining;

use OCA\Dossiq\Service\ProcessMining\PhaseSummary;
use PHPUnit\Framework\TestCase;

/**
 * The matrix's measure, rounding, labels and the case types that make no row.
 */
class PhaseSummaryMatrixTest extends TestCase {

	/**
	 * A titled case type, an untitled one and one that passed nothing.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function reports(): array {
		return [
			[
				'title' => 'Woo',
				'dwellTime' => [
					['statusName' => 'Ontvangst', 'meanHours' => 36.0, 'medianHours' => 12.0],
					['statusName' => 'Advies', 'medianHours' => 60.0],
				],
			],
			[
				'dwellTime' => [
					['statusName' => 'Besluit', 'meanHours' => 11.9, 'medianHours' => 13.0],
					['statusName' => 'Ontvangst', 'meanHours' => 1.0, 'medianHours' => 1.0],
				],
			],
			['title' => 'Leeg', 'dwellTime' => []],
		];
	}//end reports()

	/**
	 * The mean is the default; a missing value counts as zero; days round half up.
	 *
	 * @return void
	 */
	public function testTheMeanIsTheDefault(): void {
		self::assertSame(
			[
				'columns' => ['Ontvangst', 'Advies', 'Besluit'],
				'rows' => [
					['label' => 'Woo', 'values' => [2, 0, 'none']],
					['label' => '', 'values' => [0, 'none', 0]],
				],
			],
			(new PhaseSummary())->matrix(caseTypes: $this->reports())
		);
	}//end testTheMeanIsTheDefault()

	/**
	 * The median is read when asked for.
	 *
	 * @return void
	 */
	public function testTheMedianWhenAsked(): void {
		self::assertSame(
			[
				'columns' => ['Ontvangst', 'Advies', 'Besluit'],
				'rows' => [
					['label' => 'Woo', 'values' => [1, 3, 'none']],
					['label' => '', 'values' => [0, 'none', 1]],
				],
			],
			(new PhaseSummary())->matrix(caseTypes: $this->reports(), measure: 'medianHours')
		);
	}//end testTheMedianWhenAsked()

	/**
	 * No case types, no columns and no rows.
	 *
	 * @return void
	 */
	public function testNothingToRead(): void {
		self::assertSame(['columns' => [], 'rows' => []], (new PhaseSummary())->matrix(caseTypes: []));
	}//end testNothingToRead()
}//end class
