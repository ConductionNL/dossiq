<?php

/**
 * Unit tests for PerTypeRows: per-case-type report figures become rows keyed
 * by the case type, titled by it when no title is known, with the penalty
 * total in euros alongside the cents.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Reporting;

use OCA\Dossiq\Service\Reporting\PerTypeRows;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Reporting\PerTypeRows
 */
class PerTypeRowsTest extends TestCase {

	public function testFiguresBecomeRowsWithTheirTypeTitleAndEuros(): void {
		$this->assertSame(
			[
				[
					'caseType' => 'ct-1',
					'caseTypeTitle' => 'Omgevingsvergunning',
					'title' => 'Omgevingsvergunning',
					'count' => 4,
					'dwangsomTotalCents' => 123456,
					'penaltyTotalEuros' => 1234.56,
				],
				['caseType' => '42', 'caseTypeTitle' => '42', 'count' => 1, 'penaltyTotalEuros' => 0.0],
			],
			(new PerTypeRows())->listOf(
				[
					'ct-1' => ['title' => 'Omgevingsvergunning', 'count' => 4, 'dwangsomTotalCents' => 123456],
					42 => ['count' => 1],
				]
			)
		);
	}//end testFiguresBecomeRowsWithTheirTypeTitleAndEuros()

	public function testAnEmptyTitleFallsBackToTheTypeAndTheEurosAreRounded(): void {
		$rows = (new PerTypeRows())->listOf(['ct-2' => ['title' => '', 'dwangsomTotalCents' => '999']]);

		$this->assertSame('ct-2', $rows[0]['caseTypeTitle']);
		$this->assertSame(9.99, $rows[0]['penaltyTotalEuros']);
		$this->assertSame([], (new PerTypeRows())->listOf([]));
	}//end testAnEmptyTitleFallsBackToTheTypeAndTheEurosAreRounded()

	/**
	 * The CSV is the header and one line per case type, a missing figure read as 0 and a missing share as blank.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testTheCsvHasTheHeaderAndALinePerType(): void {
		$csv = (new PerTypeRows())->csv(perType: ['ct-1' => ['totaal' => 4, 'binnenTermijnPct' => 75.0, 'dwangsomTotalCents' => 120], 7 => []]);

		$this->assertSame(
			expected: "caseType,totaal,binnenTermijnPct,gemiddeldeDoorlooptijdDagen,verlengingen,overschrijdingen,ingebrekestellingen,dwangsomTotalCents\n"
				. "ct-1,4,75,0,0,0,0,120\n"
				. '7,0,,0,0,0,0,0',
			actual: $csv
		);
		$this->assertSame(expected: 0, actual: substr_count((new PerTypeRows())->csv(perType: []), "\n"), message: 'No types: the header alone.');
	}//end testTheCsvHasTheHeaderAndALinePerType()

	/**
	 * The counted figures copy the tally, every counted term received; the average is to one decimal, 0.0 for none.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testTheCountsAndTheAverage(): void {
		$rows = new PerTypeRows();
		$tally = [
			'totaal' => 5, 'verlengingen' => 1, 'overschrijdingen' => 2, 'ingebrekestellingen' => 0, 'dwangsomTotalCents' => 300,
			'met' => 2, 'missed' => 1, 'running' => 1, 'suspended' => 1,
		];

		$this->assertSame(
			expected: [
				'verlengingen' => 1, 'overschrijdingen' => 2, 'ingebrekestellingen' => 0, 'dwangsomTotalCents' => 300,
				'received' => 5, 'met' => 2, 'missed' => 1, 'running' => 1, 'suspended' => 1, 'metShare' => 66.7,
			],
			actual: $rows->counts(b: $tally, binnenPct: 66.7)
		);
		$this->assertSame(expected: 2.3, actual: $rows->averageDays(durations: [1, 2, 4]));
		$this->assertSame(expected: 0.0, actual: $rows->averageDays(durations: []));
	}//end testTheCountsAndTheAverage()
}//end class
