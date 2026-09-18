<?php

/**
 * Dwell time on two clocks, and grouped by who held it.
 *
 * The fixture pair is the one the change is named for: a phase entered on a
 * Friday at 16:00 and left on the Monday at 09:00. 65 hours passed and the
 * organisation worked one of them, and a report that cannot tell those apart
 * compares two teams on who drew the Friday afternoon cases.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\ProcessMining
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\ProcessMining;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\ProcessMining\DwellTimeAnalyzer;
use OCA\Dossiq\Service\ProcessMining\WorkingClock;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use RuntimeException;

/**
 * Two numbers per interval, and the same intervals keyed by actor.
 *
 * @covers \OCA\Dossiq\Service\ProcessMining\DwellTimeAnalyzer
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\ProcessMining\WorkingClock
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 */
class DwellOnTheWorkingCalendarTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The window every run below uses.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $from;

	/**
	 * The window's end.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $to;

	/**
	 * "Now", for a status nobody has left.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $now;

	/**
	 * Set up the shared window.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->from = new DateTimeImmutable('2026-01-01');
		$this->to = new DateTimeImmutable('2026-12-31');
		$this->now = new DateTimeImmutable('2026-12-31T00:00:00+00:00');

	}//end setUp()

	/**
	 * An analyzer measuring on the degraded clock.
	 *
	 * The engine is not on the classpath in this repository's unit run, which
	 * IS the degraded posture in production too, so this is the real path and
	 * not a stand-in for one.
	 *
	 * @return DwellTimeAnalyzer The analyzer.
	 */
	private function analyzer(): DwellTimeAnalyzer {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not registered'));

		return new DwellTimeAnalyzer(
			dates: $this->caseDates(),
			held: null,
			clock: new WorkingClock(container: $container, days: new WorkingDayCalculator())
		);
	}

	/**
	 * The Friday-to-Monday visit, as two status records on one case.
	 *
	 * @param string $actor Who the first record names.
	 *
	 * @return array<string, array<int, array<string, mixed>>> Records, keyed by case.
	 */
	private function fridayToMonday(string $actor = 'anna'): array {
		return [
			'case-1' => [
				[
					'statusType' => 'in-behandeling',
					'createdAt' => '2026-09-11T16:00:00+00:00',
					'actor' => $actor,
				],
				[
					'statusType' => 'afgehandeld',
					'createdAt' => '2026-09-14T09:00:00+00:00',
					'actor' => 'bram',
				],
			],
		];
	}

	/**
	 * Both numbers land on the interval, and they differ.
	 *
	 * @return void
	 */
	public function testAnIntervalCarriesBothNumbers(): void {
		$intervals = $this->analyzer()->computeDwellIntervals(
			recordsByCase: $this->fridayToMonday(),
			casesById: ['case-1' => ['endDate' => '2026-09-14']],
			now: $this->now,
			periodFrom: $this->from,
			periodTo: $this->to
		);

		$first = $intervals[0];
		$this->assertSame(65.0, $first['hours']);
		// Two working days on the degraded clock, and the point is that it is
		// NOT 65: a report that reported one number twice would pass any test
		// that only checked the key was present.
		$this->assertSame(16.0, $first['workingHours']);
		$this->assertNotSame($first['hours'], $first['workingHours']);
	}

	/**
	 * The per-phase summary reports both, under names that cannot be confused.
	 *
	 * `medianHours` keeps its old meaning, the wall clock, because every
	 * chart already drawn from it would otherwise change meaning silently.
	 *
	 * @return void
	 */
	public function testThePhaseSummaryReportsBothClocks(): void {
		$analyzer = $this->analyzer();
		$intervals = $analyzer->computeDwellIntervals(
			recordsByCase: $this->fridayToMonday(),
			casesById: ['case-1' => ['endDate' => '2026-09-14']],
			now: $this->now,
			periodFrom: $this->from,
			periodTo: $this->to
		);

		$stats = $analyzer->aggregateDwellStats(intervals: $intervals, statusTypeIndex: []);
		$inBehandeling = null;
		foreach ($stats as $row) {
			if ($row['statusId'] === 'in-behandeling') {
				$inBehandeling = $row;
			}
		}

		$this->assertNotNull($inBehandeling);
		$this->assertSame(65.0, $inBehandeling['medianHours']);
		$this->assertSame(16.0, $inBehandeling['medianWorkingHours']);
		$this->assertSame(16.0, $inBehandeling['p90WorkingHours']);
		$this->assertSame(16.0, $inBehandeling['meanWorkingHours']);
	}

	/**
	 * The same intervals, grouped by the handler the record names.
	 *
	 * @return void
	 */
	public function testTheSameIntervalsGroupByHandler(): void {
		$analyzer = $this->analyzer();
		$intervals = $analyzer->computeDwellIntervals(
			recordsByCase: $this->fridayToMonday(),
			casesById: ['case-1' => ['endDate' => '2026-09-14']],
			now: $this->now,
			periodFrom: $this->from,
			periodTo: $this->to
		);

		$byActor = $analyzer->aggregateDwellStatsByActor(intervals: $intervals);
		$actors = array_column($byActor, 'actor');

		$this->assertContains('anna', $actors);
		$this->assertSame(
			16.0,
			$byActor[array_search('anna', $actors, true)]['medianWorkingHours']
		);
	}

	/**
	 * Time no record attributed keeps a row of its own.
	 *
	 * Dropping it would make the by-handler table add up to less than the
	 * by-phase one beside it, with nothing on the page to say why.
	 *
	 * @return void
	 */
	public function testUnattributedTimeIsListedRatherThanDropped(): void {
		$analyzer = $this->analyzer();
		$intervals = $analyzer->computeDwellIntervals(
			recordsByCase: $this->fridayToMonday(actor: ''),
			casesById: ['case-1' => ['endDate' => '2026-09-14']],
			now: $this->now,
			periodFrom: $this->from,
			periodTo: $this->to
		);

		$byActor = $analyzer->aggregateDwellStatsByActor(intervals: $intervals);

		// The second record starts its own visit, held by bram until the
		// case closed, so two rows are correct. What matters is that the
		// unattributed one is among them rather than swallowed.
		$this->assertContains('', array_column($byActor, 'actor'));
	}

	/**
	 * An analyzer with no clock says it is on the wall clock.
	 *
	 * The one thing it must never do is call an unconverted number working
	 * hours. Every existing caller builds the analyzer this way.
	 *
	 * @return void
	 */
	public function testAnAnalyzerWithNoClockSaysSo(): void {
		$this->assertSame(
			WorkingClock::CLOCK_WALL,
			(new DwellTimeAnalyzer(dates: $this->caseDates()))->clock()
		);

		$this->assertSame(
			WorkingClock::CLOCK_DAYS_TIMES_EIGHT,
			$this->analyzer()->clock()
		);
	}

	/**
	 * Keep the normaliser import used, for the trait's benefit.
	 *
	 * @return void
	 */
	public function testTheNormaliserIsTheOneTheAppUses(): void {
		$this->assertInstanceOf(CaseDateNormaliser::class, $this->caseDates());
	}
}//end class
