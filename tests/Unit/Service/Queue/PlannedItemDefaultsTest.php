<?php

/**
 * A planned item starts at the next working day at 10:00 (DqUwWachtrij).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Queue
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
 * @spec openspec/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Queue;

use DateTimeImmutable;
use OCA\Dossiq\Service\Queue\PlannedItemDefaults;
use OCA\Dossiq\Service\WorkingDayCalculator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Queue\PlannedItemDefaults
 */
class PlannedItemDefaultsTest extends TestCase {
	/**
	 * The start the dialog opens with, from a given moment.
	 *
	 * @param string $now The moment.
	 *
	 * @return string The start.
	 */
	private function startFrom(string $now): string {
		$defaults = new PlannedItemDefaults(workingDays: new WorkingDayCalculator());
		return $defaults->startsAt(now: new DateTimeImmutable($now))['startsAt'];
	}//end startFrom()

	/**
	 * A weekday is followed by the next weekday at 10:00, whatever the hour now.
	 *
	 * @return void
	 */
	public function testAWeekdayIsFollowedByTheNextAtTen(): void {
		$this->assertSame(expected: '2026-10-14T10:00', actual: $this->startFrom(now: '2026-10-13 16:45'));
		$this->assertSame(expected: '2026-10-14T10:00', actual: $this->startFrom(now: '2026-10-13 07:00'));
	}//end testAWeekdayIsFollowedByTheNextAtTen()

	/**
	 * A Friday is followed by Monday.
	 *
	 * @return void
	 */
	public function testAFridayIsFollowedByMonday(): void {
		$this->assertSame(expected: '2026-10-12T10:00', actual: $this->startFrom(now: '2026-10-09 11:00'));
	}//end testAFridayIsFollowedByMonday()

	/**
	 * A closed day is skipped: Christmas and Boxing Day after Christmas Eve.
	 *
	 * @return void
	 */
	public function testAClosedDayIsSkipped(): void {
		$this->assertSame(expected: '2026-12-28T10:00', actual: $this->startFrom(now: '2026-12-24 09:00'));
	}//end testAClosedDayIsSkipped()
}//end class
