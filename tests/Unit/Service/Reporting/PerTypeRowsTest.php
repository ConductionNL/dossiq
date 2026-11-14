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
}//end class
