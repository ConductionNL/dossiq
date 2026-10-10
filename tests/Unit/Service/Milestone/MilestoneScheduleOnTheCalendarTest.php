<?php

/**
 * The milestone timeline counts working days on the administered calendar.
 *
 * Task 3.2 of termijnbewaking-op-engine-timers asked to replace the
 * milestone's app-local working-day math with the engine's calendar. That
 * replacement already happened underneath it: `WorkingDayCalculator` asks
 * OpenRegister's administered calendar through `WorkingDayRoll` and keeps
 * its own Dutch list only as the fallback. This is the fixture pair the task
 * asked for, pinned at the milestone level: across a weekend and Dutch
 * holidays the schedule lands on the same days on the engine calendar as on
 * the list, and an administered closure day the list does not know moves it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Milestone
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Milestone;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Service\Milestone\MilestoneSchedule;
use OCA\Dossiq\Service\Termijn\WorkingDayRoll;
use OCA\Dossiq\Service\WorkingDayCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Same days on the calendar as on the list, and a closure day honoured.
 */
class MilestoneScheduleOnTheCalendarTest extends TestCase {

	/**
	 * An administered calendar: Monday to Friday, closed on the given days.
	 *
	 * @param string[] $closures ISO dates the organisation is closed.
	 *
	 * @return WorkingDayRoll
	 */
	private function calendar(array $closures): WorkingDayRoll {
		$roll = $this->getMockBuilder(WorkingDayRoll::class)
			->disableOriginalConstructor()
			->onlyMethods(['isAvailable', 'worksOn', 'workingWeekdays'])
			->getMock();
		$roll->method('isAvailable')->willReturn(true);
		$roll->method('workingWeekdays')->willReturn([1, 2, 3, 4, 5]);
		$roll->method('worksOn')->willReturnCallback(
			static fn (DateTimeInterface $d): bool => (int) $d->format('N') <= 5 && in_array($d->format('Y-m-d'), $closures, true) === false
		);
		return $roll;
	}//end calendar()

	/**
	 * Two milestones, the second waiting on the first, across Ascension and Whit Monday 2026.
	 *
	 * @param WorkingDayCalculator $days The working-day source.
	 *
	 * @return array<string, string> Projected dates by identifier.
	 */
	private function project(WorkingDayCalculator $days): array {
		$schedule = new MilestoneSchedule(workingDays: $days);
		$dates    = $schedule->project(
			definitions: [
				['id' => 'd1', 'identifier' => 'intake', 'order' => 1, 'expectedDurationWorkingDays' => 3, 'dependsOn' => []],
				['id' => 'd2', 'identifier' => 'besluit', 'order' => 2, 'expectedDurationWorkingDays' => 7, 'dependsOn' => ['intake']],
			],
			caseStart: new DateTimeImmutable('2026-05-12')
		);

		return array_map(static fn (DateTimeImmutable $d): string => $d->format('Y-m-d'), $dates);
	}//end project()

	/**
	 * The engine calendar holding the Dutch holidays lands on the list's days.
	 *
	 * @return void
	 */
	public function testTheCalendarAndTheListAgreeAcrossHolidays(): void {
		$onTheList     = $this->project(days: new WorkingDayCalculator());
		$onTheCalendar = $this->project(days: new WorkingDayCalculator(administered: $this->calendar(closures: ['2026-05-14', '2026-05-25'])));

		// Tue 12 May + 3 working days skips Ascension (Thu 14) and the weekend: Mon 18.
		// + 7 more skips the weekend and Whit Monday (25): Thu 28.
		$this->assertSame(expected: ['intake' => '2026-05-18', 'besluit' => '2026-05-28'], actual: $onTheList);
		$this->assertSame(expected: $onTheList, actual: $onTheCalendar);
	}//end testTheCalendarAndTheListAgreeAcrossHolidays()

	/**
	 * A closure day only the organisation administers moves the milestone.
	 *
	 * @return void
	 */
	public function testAnAdministeredClosureDayMovesTheMilestone(): void {
		$closed = $this->project(days: new WorkingDayCalculator(administered: $this->calendar(closures: ['2026-05-14', '2026-05-25', '2026-05-15'])));

		$this->assertSame(expected: '2026-05-19', actual: $closed['intake']);
	}//end testAnAdministeredClosureDayMovesTheMilestone()
}//end class
