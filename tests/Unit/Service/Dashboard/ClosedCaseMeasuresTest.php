<?php

/**
 * Unit tests for the measures over closed cases.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Dashboard
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/dashboard/spec.md#REQ-DASH-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Dashboard;

use OCA\Dossiq\Service\Dashboard\ClosedCaseMeasures;
use OCA\Dossiq\Service\Term\DayCount;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Dashboard\ClosedCaseMeasures
 * @uses   \OCA\Dossiq\Service\Term\DayCount
 */
class ClosedCaseMeasuresTest extends TestCase {

	/**
	 * The average leaves out a row whose dates cannot be read.
	 *
	 * @return void
	 */
	public function testTheAverageCountsOnlyReadableDurations(): void {
		$measures = new ClosedCaseMeasures(days: new DayCount());

		$average = $measures->averageProcessingDays(
			closed: [
				['startDate' => '2026-11-01', 'endDate' => '2026-11-11'],
				['startDate' => '2026-11-01T09:00:00+01:00', 'endDate' => '2026-11-06'],
				['startDate' => '', 'endDate' => '2026-11-20'],
			]
		);

		self::assertSame(expected: 7.5, actual: $average);
		self::assertNull(actual: $measures->averageProcessingDays(closed: []));
	}//end testTheAverageCountsOnlyReadableDurations()

	/**
	 * Compliance is the share of measurable cases closed on or before their deadline.
	 *
	 * @return void
	 */
	public function testComplianceIsTheShareClosedByTheDeadline(): void {
		$measures = new ClosedCaseMeasures(days: new DayCount());

		$compliance = $measures->slaCompliance(
			closed: [
				['deadline' => '2026-11-10', 'endDate' => '2026-11-10'],
				['deadline' => '2026-11-10', 'endDate' => '2026-11-12'],
				['deadline' => '2026-11-30', 'endDate' => '2026-11-01'],
				['deadline' => '', 'endDate' => '2026-11-01'],
			]
		);

		self::assertSame(expected: 67.0, actual: $compliance);
		self::assertNull(actual: $measures->slaCompliance(closed: [['endDate' => '2026-11-01']]));
	}//end testComplianceIsTheShareClosedByTheDeadline()
}//end class
