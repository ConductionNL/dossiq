<?php

/**
 * The norm line of the processing-time chart.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Doorlooptijd
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/doorlooptijd-norm-and-months/spec.md#requirement-the-processing-time-chart-draws-the-norm-and-names-the-months
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Doorlooptijd;

use DateTimeImmutable;
use OCA\Dossiq\Service\Doorlooptijd\NormAverage;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Doorlooptijd\NormAverage
 */
class NormAverageTest extends TestCase {
	/**
	 * One enriched case.
	 *
	 * @param string|null $end  The end date, null while open.
	 * @param int|null    $norm The term in days.
	 *
	 * @return array<string, mixed>
	 */
	private function closed(?string $end, ?int $norm): array {
		$row = ['_isOpen' => $end === null, '_endDate' => $end];
		if ($norm !== null) {
			$row['_normDays'] = $norm;
		}

		return $row;
	}//end closed()

	/**
	 * The average term of the cases closed in the last twelve months
	 * (DqDoorlooptijd "norm 28 dagen"); open, older and termless cases do
	 * not count.
	 *
	 * @return void
	 */
	public function testAveragesTheTermOfTheRecentlyClosedCases(): void {
		$cases = [
			$this->closed('2026-09-10', 28),
			$this->closed('2026-09-20', 28),
			$this->closed('2026-08-05', 56),
			$this->closed('2025-06-01', 84),
			$this->closed(null, 28),
			$this->closed('2026-09-01', null),
		];

		self::assertSame(37, (new NormAverage())->averageOf($cases, new DateTimeImmutable('2026-10-16')));
	}//end testAveragesTheTermOfTheRecentlyClosedCases()

	/**
	 * No term among them: no norm line.
	 *
	 * @return void
	 */
	public function testNoTermNoNorm(): void {
		self::assertNull((new NormAverage())->averageOf([$this->closed('2026-09-01', null)], new DateTimeImmutable('2026-10-16')));
	}//end testNoTermNoNorm()
}//end class
