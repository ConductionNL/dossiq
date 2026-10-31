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
	 * The columns follow the case types' flows: Advies, which one case type
	 * passes before Besluit, goes before it although the first case type read
	 * never passes it and lists Besluit first.
	 *
	 * @return void
	 */
	public function testColumnsFollowTheCaseTypesFlowOrder(): void {
		$stat = static fn (string $phase, int $order): array => [
			'statusName' => $phase . ' status',
			'statusPhase' => $phase,
			'statusOrder' => $order,
			'meanHours' => 24.0,
			'medianHours' => 24.0,
		];
		$reports = [
			[
				'id' => 'woo',
				'title' => 'Woo-verzoek',
				'dwellTime' => [$stat('Besluit', 3), $stat('Ontvangst', 1), $stat('Beoordeling', 2), $stat('Verzending', 4)],
			],
			['id' => 'bouw', 'title' => 'Bouw', 'dwellTime' => [$stat('Ontvangst', 1), $stat('Beoordeling', 2), $stat('Advies', 3), $stat('Besluit', 4)]],
		];

		$matrix = (new PhaseSummary())->matrix(caseTypes: $reports, measure: 'meanHours');

		self::assertSame(expected: ['Ontvangst', 'Beoordeling', 'Advies', 'Besluit', 'Verzending'], actual: $matrix['columns']);
	}//end testColumnsFollowTheCaseTypesFlowOrder()

	/**
	 * Phases become columns in first-seen order; a phase a case type never passes reads none.
	 *
	 * @return void
	 */
	public function testMatrixHasAColumnPerPhase(): void {
		$matrix = (new PhaseSummary())->matrix(caseTypes: $this->reports(), measure: 'meanHours');

		self::assertSame(expected: ['Ontvangst', 'Advies'], actual: $matrix['columns']);
		self::assertSame(expected: ['label' => 'Woo-verzoek', 'values' => [2, 6]], actual: $matrix['rows'][0]);
		self::assertSame(expected: ['label' => 'Melding', 'values' => [1, 'none']], actual: $matrix['rows'][1]);
	}//end testMatrixHasAColumnPerPhase()

	/**
	 * A status the index could not name is no column, and a case type with
	 * only such statuses is no row.
	 *
	 * @return void
	 */
	public function testUnnamedStatusesAreLeftOut(): void {
		$reports = $this->reports();
		$reports[] = [
			'id' => 'bezwaar',
			'title' => 'Bezwaar',
			'dwellTime' => [['statusId' => 'uuid-1', 'statusName' => 'uuid-1', 'meanHours' => 48.0, 'medianHours' => 48.0]],
		];

		$matrix = (new PhaseSummary())->matrix(caseTypes: $reports, measure: 'meanHours');

		self::assertSame(expected: ['Ontvangst', 'Advies'], actual: $matrix['columns']);
		self::assertCount(expectedCount: 2, haystack: $matrix['rows']);
	}//end testUnnamedStatusesAreLeftOut()

	/**
	 * A case type whose statuses name a phase is read by phase: the days of
	 * the statuses of one phase add up, a final status is no column, a status
	 * without a phase keeps its own name; a case type without phases keeps
	 * its status names.
	 *
	 * @return void
	 */
	public function testStatusesGroupByTheirPhase(): void {
		$reports = [
			[
				'id' => 'woo',
				'title' => 'Woo-verzoek',
				'dwellTime' => [
					['statusId' => 's1', 'statusName' => 'Ontvangst', 'statusPhase' => 'Ontvangst', 'meanHours' => 24.0, 'medianHours' => 24.0],
					['statusId' => 's2', 'statusName' => 'Zoeken documenten', 'statusPhase' => 'Beoordeling', 'meanHours' => 96.0, 'medianHours' => 96.0],
					['statusId' => 's3', 'statusName' => 'Lakken', 'statusPhase' => 'Beoordeling', 'meanHours' => 120.0, 'medianHours' => 96.0],
					['statusId' => 's5', 'statusName' => 'Hercontrole', 'statusPhase' => '', 'meanHours' => 72.0, 'medianHours' => 72.0],
					['statusId' => 's4', 'statusName' => 'Afgehandeld', 'statusPhase' => '', 'statusFinal' => true, 'meanHours' => 2.0, 'medianHours' => 2.0],
				],
			],
			[
				'id' => 'klacht',
				'title' => 'Klacht',
				'dwellTime' => [['statusId' => 'k1', 'statusName' => 'Onderzoek', 'meanHours' => 48.0, 'medianHours' => 48.0]],
			],
		];

		$matrix = (new PhaseSummary())->matrix(caseTypes: $reports, measure: 'meanHours');

		self::assertSame(expected: ['Ontvangst', 'Beoordeling', 'Hercontrole', 'Onderzoek'], actual: $matrix['columns']);
		self::assertSame(expected: ['label' => 'Woo-verzoek', 'values' => [1, 9, 3, 'none']], actual: $matrix['rows'][0]);
		self::assertSame(expected: ['label' => 'Klacht', 'values' => ['none', 'none', 'none', 2]], actual: $matrix['rows'][1]);
	}//end testStatusesGroupByTheirPhase()

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
