<?php

/**
 * The incident record's tolerance of malformed rows and unreadable dates.
 *
 * A stored incident list is register data, so a row may be something other
 * than an array and a date may be text nobody can parse. Neither may be
 * counted as open work, and neither may head the event order.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\IncidentRecord;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Malformed rows and unparseable dates.
 *
 * @covers \OCA\Dossiq\Service\Cases\IncidentRecord
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Term\DayCount
 * @uses \OCA\Dossiq\Service\SettingsService
 *
 * @spec openspec/specs/case-management/spec.md
 */
class IncidentRecordTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * A row that is not an array is not open work.
	 *
	 * @return void
	 */
	public function testANonArrayRowIsNotCountedAsOpen(): void {
		$record = new IncidentRecord(dates: $this->caseDates());

		self::assertSame(
			2,
			$record->openCount(incidents: ['garbage', ['state' => 'open'], null, ['state' => 'afgehandeld'], []]),
		);
	}//end testANonArrayRowIsNotCountedAsOpen()

	/**
	 * An unparseable date has no delay and sorts last.
	 *
	 * @return void
	 */
	public function testAnUnparseableDateHasNoDelayAndSortsLast(): void {
		$record = new IncidentRecord(dates: $this->caseDates());

		self::assertNull(
			$record->recordingDelayDays(
				incident: ['eventDate' => 'not a date at all', 'recordedAt' => '2026-03-22T09:00:00+01:00'],
			),
		);

		$ordered = $record->inEventOrder(
			incidents: [
				['description' => 'Kapot', 'eventDate' => 'gisteren-ish ???'],
				['description' => 'Maart', 'eventDate' => '2026-03-21T09:00:00+01:00'],
			],
		);

		self::assertSame(['Maart', 'Kapot'], array_column($ordered, 'description'));
	}//end testAnUnparseableDateHasNoDelayAndSortsLast()
}//end class
