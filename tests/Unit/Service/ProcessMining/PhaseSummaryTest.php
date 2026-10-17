<?php

/**
 * PhaseSummary unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\ProcessMining
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\ProcessMining;

use OCA\Dossiq\Service\ProcessMining\PhaseSummary;
use PHPUnit\Framework\TestCase;

/**
 * The matrix and the stuck list the process analysis page leads with.
 *
 * @covers \OCA\Dossiq\Service\ProcessMining\PhaseSummary
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
class PhaseSummaryTest extends TestCase {

	/**
	 * Two case types, one of which never passes the advice phase.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function reports(): array {
		return [
			[
				'id' => 'woo',
				'title' => 'Woo-verzoek',
				'dwellTime' => [
					['statusName' => 'Ontvangst', 'meanHours' => 48.0, 'medianHours' => 24.0],
					['statusName' => 'Advies', 'meanHours' => 144.0, 'medianHours' => 120.0],
				],
				'bottlenecks' => [['statusId' => 's-advies', 'statusName' => 'Advies', 'visitCount' => 3, 'medianHours' => 120.0, 'score' => 360.0]],
			],
			[
				'id' => 'melding',
				'title' => 'Melding',
				'dwellTime' => [
					['statusName' => 'Ontvangst', 'meanHours' => 24.0, 'medianHours' => 24.0],
				],
				'bottlenecks' => [['statusId' => 's-ontvangst', 'statusName' => 'Ontvangst', 'visitCount' => 9, 'medianHours' => 24.0, 'score' => 216.0]],
			],
		];
	}//end reports()

	/**
	 * Phases become columns in first-seen order; a phase a case type never passes is null.
	 *
	 * @return void
	 */
	public function testMatrixHasAColumnPerPhase(): void {
		$matrix = (new PhaseSummary())->matrix(caseTypes: $this->reports(), measure: 'meanHours');

		self::assertSame(expected: ['Ontvangst', 'Advies'], actual: $matrix['columns']);
		self::assertSame(expected: ['label' => 'Woo-verzoek', 'values' => [2, 6]], actual: $matrix['rows'][0]);
		self::assertSame(expected: ['label' => 'Melding', 'values' => [1, null]], actual: $matrix['rows'][1]);
	}//end testMatrixHasAColumnPerPhase()

	/**
	 * The worst case type's bottleneck leads and is the only one marked as such.
	 *
	 * @return void
	 */
	public function testStuckRanksAcrossCaseTypes(): void {
		$stuck = (new PhaseSummary())->stuck(caseTypes: $this->reports());

		self::assertCount(expectedCount: 2, haystack: $stuck);
		self::assertSame(expected: 'Advies', actual: $stuck[0]['phase']);
		self::assertSame(expected: 5, actual: $stuck[0]['days']);
		self::assertSame(expected: 'bottleneck', actual: $stuck[0]['level']);
		self::assertSame(expected: 'watch', actual: $stuck[1]['level']);
	}//end testStuckRanksAcrossCaseTypes()
}//end class
