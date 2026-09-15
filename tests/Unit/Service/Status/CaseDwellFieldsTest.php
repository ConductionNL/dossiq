<?php

/**
 * The dwell numbers the case carries.
 *
 * Written as the status changes, counted on working days, and never touching
 * the term. The last of those is the one this file pins hardest: a status
 * maximum and a beslistermijn are different clocks with different
 * consequences, and a change that quietly moved a deadline would be a change
 * nobody could see going wrong.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Status
 *
 * @author    Conduction Development Team <info@conduction.nl>
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
 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Status;

use DateTimeImmutable;
use OCA\Dossiq\Service\Status\StatusDwellService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Status\StatusDwellService
 *
 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
 */
class CaseDwellFieldsTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The service under test.
	 *
	 * @var StatusDwellService
	 */
	private StatusDwellService $dwell;

	/**
	 * Build the service over the real working-day calendar.
	 *
	 * The real one rather than a double: the whole point of the number is
	 * which days it does not count, and a double that counted every day would
	 * pass every assertion below while shipping a wrong column.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->dwell = new StatusDwellService(
			calendar: new WorkingDayCalculator(),
			dates: $this->caseDates(),
		);
	}//end setUp()

	/**
	 * Leaving a status banks what was spent there and opens the next.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
	 */
	public function testAMoveBanksTheTimeSpentInTheStatusItLeaves(): void {
		// Monday 1 June 2026 to Monday 8 June 2026: five working days between.
		$case = [
			'status' => 'intake',
			'currentStatusEnteredAt' => '2026-06-01T09:00:00+02:00',
		];

		$moved = $this->dwell->applyStatusChange(
			case: $case,
			toStatus: 'review',
			now: new DateTimeImmutable('2026-06-08T09:00:00+02:00'),
		);

		self::assertSame(
			expected: [['statusType' => 'intake', 'workingDays' => 5]],
			actual: $moved['statusDwellTotals'],
		);
		self::assertSame(expected: 0, actual: $moved['currentStatusDwellDays']);
		self::assertFalse(condition: $moved['statusDwellBreached']);
		self::assertStringStartsWith(prefix: '2026-06-08', string: $moved['currentStatusEnteredAt']);
	}//end testAMoveBanksTheTimeSpentInTheStatusItLeaves()

	/**
	 * A second visit to the same status adds to the same total.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
	 */
	public function testASecondVisitAddsToTheSameStatusTotal(): void {
		$case = [
			'status' => 'intake',
			'currentStatusEnteredAt' => '2026-06-01T09:00:00+02:00',
			'statusDwellTotals' => [
				['statusType' => 'intake', 'workingDays' => 3],
				['statusType' => 'review', 'workingDays' => 7],
			],
		];

		$moved = $this->dwell->applyStatusChange(
			case: $case,
			toStatus: 'review',
			now: new DateTimeImmutable('2026-06-05T09:00:00+02:00'),
		);

		self::assertSame(
			expected: [
				['statusType' => 'intake', 'workingDays' => 7],
				['statusType' => 'review', 'workingDays' => 7],
			],
			actual: $moved['statusDwellTotals'],
		);
	}//end testASecondVisitAddsToTheSameStatusTotal()

	/**
	 * The count is working days, so a weekend and a holiday do not count.
	 *
	 * Entered on Thursday 30 April 2026, read on Wednesday 6 May: Friday the
	 * 1st, Monday the 4th and Wednesday the 6th are working days, Tuesday 5
	 * May is Bevrijdingsdag and the 2nd and 3rd are the weekend.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
	 */
	public function testTheCountSkipsTheWeekendAndTheGeneralHoliday(): void {
		$case = ['status' => 'review', 'currentStatusEnteredAt' => '2026-04-30T09:00:00+02:00'];

		self::assertSame(
			expected: 3,
			actual: $this->dwell->elapsedWorkingDays(
				case: $case,
				now: new DateTimeImmutable('2026-05-06T09:00:00+02:00'),
			),
		);
	}//end testTheCountSkipsTheWeekendAndTheGeneralHoliday()

	/**
	 * A case nobody recorded entering a status answers zero, not a guess.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
	 */
	public function testACaseWithNoEntryMomentAndNoStartDateAnswersZero(): void {
		self::assertSame(
			expected: 0,
			actual: $this->dwell->elapsedWorkingDays(
				case: ['status' => 'review'],
				now: new DateTimeImmutable('2026-06-08T09:00:00+02:00'),
			),
		);
	}//end testACaseWithNoEntryMomentAndNoStartDateAnswersZero()

	/**
	 * The totals a reader gets fold in the time still running.
	 *
	 * The stored totals stop at the last transition. A reader who had to
	 * remember that is a reader who will not, and the page and the case would
	 * then disagree about the current status by exactly the time the case has
	 * been in it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/doorlooptijd-dashboard/spec.md
	 */
	public function testTheTotalsFoldInTheStatusStillRunning(): void {
		$case = [
			'status' => 'review',
			'currentStatusEnteredAt' => '2026-06-01T09:00:00+02:00',
			'statusDwellTotals' => [
				['statusType' => 'intake', 'workingDays' => 4],
				['statusType' => 'review', 'workingDays' => 2],
			],
		];

		self::assertSame(
			expected: ['intake' => 4, 'review' => 7],
			actual: $this->dwell->totalsFor(
				case: $case,
				now: new DateTimeImmutable('2026-06-08T09:00:00+02:00'),
			),
		);
	}//end testTheTotalsFoldInTheStatusStillRunning()

	/**
	 * Nothing about the dwell touches the term.
	 *
	 * The case below breaches its status maximum with eight weeks left on its
	 * deadline. Its `deadline`, `endDate` and `status` come out of the move
	 * exactly as they went in, which is the whole of D-5 stated as an
	 * assertion rather than as a comment.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function testTheDwellNeverTouchesTheTerm(): void {
		$case = [
			'status' => 'review',
			'deadline' => '2026-09-01',
			'endDate' => '',
			'statutoryTerm' => 'P8W',
			'currentStatusEnteredAt' => '2026-06-01T09:00:00+02:00',
		];

		$moved = $this->dwell->applyStatusChange(
			case: $case,
			toStatus: 'decision',
			now: new DateTimeImmutable('2026-07-06T09:00:00+02:00'),
		);

		self::assertSame(expected: '2026-09-01', actual: $moved['deadline']);
		self::assertSame(expected: '', actual: $moved['endDate']);
		self::assertSame(expected: 'P8W', actual: $moved['statutoryTerm']);
		// The move does not set the status itself; the engine does, after this.
		self::assertSame(expected: 'review', actual: $moved['status']);
	}//end testTheDwellNeverTouchesTheTerm()
}//end class
