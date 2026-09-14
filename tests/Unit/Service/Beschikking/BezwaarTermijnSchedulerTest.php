<?php

/**
 * Unit tests for BezwaarTermijnScheduler.
 *
 * The six weeks of Awb 6:7 were never in doubt. What this file pins is where
 * they LAND: a fixture pair per case, the same bekendmaking with the roll on
 * and off, so a term that stops reaching the calendar cannot pass.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
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
 * @spec openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\BezwaarTermijnScheduler;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Unit\Service\SlaCalculatorFake;
use OCA\Dossiq\Tests\Unit\Service\WorkingCalendarFake;
use OCA\Dossiq\Tests\Unit\Service\WorkingCalendarServiceFake;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\BezwaarTermijnScheduler
 *
 * @uses \OCA\Dossiq\Service\TermijnTimerService
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 */
class BezwaarTermijnSchedulerTest extends TestCase {
	/**
	 * A scheduler wired to an engine calendar that closes on the given dates.
	 *
	 * @param array<int, string> $closed Non-working dates as `Y-m-d`.
	 *
	 * @return BezwaarTermijnScheduler The scheduler under test.
	 */
	private function scheduler(array $closed): BezwaarTermijnScheduler {
		$calendars = new WorkingCalendarServiceFake(new WorkingCalendarFake('nl-national', $closed));
		$calculator = new SlaCalculatorFake();

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturnCallback(
			static function (string $class) use ($calendars, $calculator): ?object {
				return match ($class) {
					TermijnTimerService::CALENDAR_SERVICE_CLASS => $calendars,
					TermijnTimerService::SLA_CALCULATOR_CLASS => $calculator,
					default => null,
				};
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$timers = new TermijnTimerService($settings, $logger, new WorkingDayCalculator());

		return new BezwaarTermijnScheduler($this->createMock(SettingsService::class), $logger, $timers);
	}

	/**
	 * Six weeks from 15 February 2027 is Tweede Paasdag. The term runs to the
	 * Tuesday; the reminder a week earlier is already an ordinary Monday and
	 * does not move.
	 *
	 * @return void
	 */
	public function testATermEndingOnTweedePaasdagRunsToTheNextOrdinaryDay(): void {
		$term = $this->scheduler(['2027-03-29'])->computeTermijn(bekendmaking: '2027-02-15');

		self::assertSame('2027-03-30', $term['endDate']);
		self::assertSame('2027-03-22', $term['herinnering']);
	}

	/**
	 * The same bekendmaking with the roll switched off keeps the raw date, so
	 * the pair proves the roll and not the arithmetic.
	 *
	 * @return void
	 */
	public function testTheSameTermWithoutTheRollEndsOnTweedePaasdag(): void {
		$term = $this->scheduler(['2027-03-29'])->computeTermijn(
			bekendmaking: '2027-02-15',
			definitie: ['rollToWorkingDay' => false]
		);

		self::assertSame('2027-03-29', $term['endDate']);
		self::assertSame('2027-03-22', $term['herinnering']);
	}

	/**
	 * Koningsdag 2026 falls on a Monday, so a calendar that only knew about
	 * weekends would leave the term on it. The reminder is unaffected.
	 *
	 * @return void
	 */
	public function testATermEndingOnKoningsdagRunsToTheNextOrdinaryDay(): void {
		$term = $this->scheduler(['2026-04-27'])->computeTermijn(bekendmaking: '2026-03-16');

		self::assertSame('2026-04-28', $term['endDate']);
		self::assertSame('2026-04-20', $term['herinnering']);
	}

	/**
	 * A reminder landing on a closed day moves as well: one that arrives on
	 * Tweede Kerstdag reaches nobody.
	 *
	 * @return void
	 */
	public function testAReminderOnAClosedDayMovesToo(): void {
		// Six weeks from 21 November 2026 ends on 2 January 2027; the reminder
		// a week earlier is Tweede Kerstdag, a Saturday.
		$term = $this->scheduler(['2026-12-26', '2027-01-02'])->computeTermijn(bekendmaking: '2026-11-21');

		self::assertSame('2027-01-04', $term['endDate']);
		self::assertSame('2026-12-28', $term['herinnering']);
	}

	/**
	 * A term already ending on an ordinary day is untouched, so the roll never
	 * lengthens a term that does not need it.
	 *
	 * @return void
	 */
	public function testAnOrdinaryEndDateIsUnchanged(): void {
		$term = $this->scheduler([])->computeTermijn(bekendmaking: '2026-02-02');

		self::assertSame('2026-03-16', $term['endDate']);
		self::assertSame('2026-03-09', $term['herinnering']);
	}
}
