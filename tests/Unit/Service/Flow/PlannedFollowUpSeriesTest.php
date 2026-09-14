<?php

/**
 * Planned follow-up series unit tests.
 *
 * The arithmetic here is the whole of what a series promises, and every part of
 * it fails silently when it regresses: a cron day that does not exist in every
 * month skips months without saying so, `+1 month` overflows 31 August into 1
 * October, and a spent-check that is off by one either stops a series early or
 * never stops it at all.
 *
 * The corpus behind row 1.8 found Kanboard and Vikunja both getting the
 * month-end wrong, which is why the 31 August case is asserted rather than
 * assumed.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use DateTimeImmutable;
use OCA\Dossiq\Service\Flow\PlannedFollowUpDocument;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A planned follow-up that repeats.
 *
 * @covers \OCA\Dossiq\Service\Flow\PlannedFollowUpDocument
 * @covers \OCA\Dossiq\Service\Flow\PlannedSeriesCalendar
 */
class PlannedFollowUpSeriesTest extends TestCase {

	/**
	 * The class under test.
	 *
	 * @var PlannedFollowUpDocument
	 */
	private PlannedFollowUpDocument $document;

	/**
	 * Build the pure document class.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->document = new PlannedFollowUpDocument();
	}//end setUp()

	/**
	 * A follow-up with no recurrence writes exactly the cron it wrote before.
	 *
	 * @return void
	 */
	public function testNoRecurrenceKeepsThePinnedCron(): void {
		$built = $this->document->build(
			caseId: 'case-1',
			caseTypeId: 'type-1',
			due: new DateTimeImmutable('2026-10-15'),
			title: 'Controle',
			uid: 'alice'
		);

		$this->assertSame('0 6 15 10 *', $built['cron']);
		$this->assertSame('0 6 15 10 *', $built['nodes'][0]['config']['cron']);
		$this->assertSame('alice', $built['nodes'][0]['config']['runAs']);
		$this->assertSame('none', $built['nodes'][1]['config']['series']['recurrence']);
	}//end testNoRecurrenceKeepsThePinnedCron()

	/**
	 * Each recurrence writes the cron fields that express it.
	 *
	 * @param string $recurrence The recurrence token.
	 * @param string $due The first occurrence.
	 * @param string $expected The cron expression.
	 *
	 * @return void
	 *
	 * @dataProvider recurrenceCrons
	 */
	public function testEachRecurrenceWritesItsCron(string $recurrence, string $due, string $expected): void {
		$built = $this->document->build(
			caseId: 'case-1',
			caseTypeId: 'type-1',
			due: new DateTimeImmutable($due),
			title: 'Controle',
			uid: 'alice',
			recurrence: $recurrence
		);

		$this->assertSame($expected, $built['cron']);
		$this->assertSame($expected, $built['nodes'][0]['config']['cron']);

		// A series keeps runAs on every occurrence, which is the same thing as
		// keeping it on the one trigger every occurrence starts from.
		$this->assertSame('alice', $built['nodes'][0]['config']['runAs']);
	}//end testEachRecurrenceWritesItsCron()

	/**
	 * The recurrence-to-cron pairs.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}> The cases.
	 */
	public static function recurrenceCrons(): array {
		return [
			'monthly on the 15th' => ['monthly', '2026-10-15', '0 6 15 * *'],
			'monthly on the 31st runs month-end' => ['monthly', '2026-08-31', '0 6 L * *'],
			'monthly on the 29th runs month-end' => ['monthly', '2026-01-29', '0 6 L * *'],
			'quarterly from October' => ['quarterly', '2026-10-15', '0 6 15 1,4,7,10 *'],
			'quarterly from August month-end' => ['quarterly', '2026-08-31', '0 6 L 2,5,8,11 *'],
			'half-yearly from March' => ['halfYearly', '2026-03-01', '0 6 1 3,9 *'],
			'yearly on the 31st of a 31-day month' => ['yearly', '2026-08-31', '0 6 31 8 *'],
			'yearly on 29 February runs month-end' => ['yearly', '2028-02-29', '0 6 L 2 *'],
		];
	}//end recurrenceCrons()

	/**
	 * 31 August plus one month is 30 September, not 1 October.
	 *
	 * @return void
	 */
	public function testAMonthEndSeriesClampsRatherThanOverflows(): void {
		$anchor = new DateTimeImmutable('2026-08-31');

		$this->assertSame(
			'2026-09-30',
			$this->document->nextOccurrence(
				recurrence: 'monthly',
				anchor: $anchor,
				from: $anchor
			)?->format('Y-m-d')
		);

		// And the month after that is back to 31: each occurrence is measured
		// from the anchor, so a clamped month cannot drag the series earlier.
		$this->assertSame(
			'2026-10-31',
			$this->document->nextOccurrence(
				recurrence: 'monthly',
				anchor: $anchor,
				from: new DateTimeImmutable('2026-09-30')
			)?->format('Y-m-d')
		);

		// February is the shortest clamp of all, and a leap year is 29.
		$this->assertSame(
			'2027-02-28',
			$this->document->nextOccurrence(
				recurrence: 'monthly',
				anchor: $anchor,
				from: new DateTimeImmutable('2027-01-31')
			)?->format('Y-m-d')
		);
		$this->assertSame(
			'2028-02-29',
			$this->document->nextOccurrence(
				recurrence: 'monthly',
				anchor: $anchor,
				from: new DateTimeImmutable('2028-01-31')
			)?->format('Y-m-d')
		);
	}//end testAMonthEndSeriesClampsRatherThanOverflows()

	/**
	 * A series steps over the turn of the year.
	 *
	 * @return void
	 */
	public function testASeriesCrossesTheYearBoundary(): void {
		$this->assertSame(
			'2027-01-15',
			$this->document->nextOccurrence(
				recurrence: 'monthly',
				anchor: new DateTimeImmutable('2026-12-15'),
				from: new DateTimeImmutable('2026-12-20')
			)?->format('Y-m-d')
		);

		$this->assertSame(
			'2027-02-15',
			$this->document->nextOccurrence(
				recurrence: 'quarterly',
				anchor: new DateTimeImmutable('2026-11-15'),
				from: new DateTimeImmutable('2026-12-31')
			)?->format('Y-m-d')
		);

		$this->assertSame(
			'2027-03-01',
			$this->document->nextOccurrence(
				recurrence: 'yearly',
				anchor: new DateTimeImmutable('2026-03-01'),
				from: new DateTimeImmutable('2026-07-01')
			)?->format('Y-m-d')
		);

		// The anchor itself is never the NEXT occurrence when it has passed,
		// and it is when it has not.
		$this->assertSame(
			'2026-03-01',
			$this->document->nextOccurrence(
				recurrence: 'yearly',
				anchor: new DateTimeImmutable('2026-03-01'),
				from: new DateTimeImmutable('2026-02-28')
			)?->format('Y-m-d')
		);
	}//end testASeriesCrossesTheYearBoundary()

	/**
	 * A follow-up with no recurrence has one occurrence and then none.
	 *
	 * @return void
	 */
	public function testNoRecurrenceHasOneOccurrence(): void {
		$anchor = new DateTimeImmutable('2026-10-15');

		$this->assertSame(
			'2026-10-15',
			$this->document->nextOccurrence(
				recurrence: 'none',
				anchor: $anchor,
				from: new DateTimeImmutable('2026-10-14')
			)?->format('Y-m-d')
		);
		$this->assertNull(
			$this->document->nextOccurrence(
				recurrence: 'none',
				anchor: $anchor,
				from: $anchor
			)
		);
	}//end testNoRecurrenceHasOneOccurrence()

	/**
	 * A single follow-up is spent the moment it has fired.
	 *
	 * @return void
	 */
	public function testASingleFollowUpIsSpentAfterOneFire(): void {
		$built = $this->document->build(
			caseId: 'case-1',
			caseTypeId: 'type-1',
			due: new DateTimeImmutable('2026-10-15'),
			title: 'Controle',
			uid: 'alice'
		);

		$today = new DateTimeImmutable('2026-10-15');
		$this->assertFalse($this->document->isSpent(document: $built, firedCount: 0, today: $today));
		$this->assertTrue($this->document->isSpent(document: $built, firedCount: 1, today: $today));
	}//end testASingleFollowUpIsSpentAfterOneFire()

	/**
	 * A series with a count is spent when the count is reached.
	 *
	 * @return void
	 */
	public function testACountedSeriesIsSpentOnItsLastOccurrence(): void {
		$built = $this->document->build(
			caseId: 'case-1',
			caseTypeId: 'type-1',
			due: new DateTimeImmutable('2026-10-15'),
			title: 'Jaarlijkse controle',
			uid: 'alice',
			recurrence: 'yearly',
			count: 3
		);

		$today = new DateTimeImmutable('2028-10-15');
		$this->assertFalse($this->document->isSpent(document: $built, firedCount: 1, today: $today));
		$this->assertFalse($this->document->isSpent(document: $built, firedCount: 2, today: $today));
		$this->assertTrue($this->document->isSpent(document: $built, firedCount: 3, today: $today));
	}//end testACountedSeriesIsSpentOnItsLastOccurrence()

	/**
	 * A series with an end date is spent when no occurrence is left before it.
	 *
	 * @return void
	 */
	public function testADatedSeriesIsSpentWhenItsEndHasPassed(): void {
		$built = $this->document->build(
			caseId: 'case-1',
			caseTypeId: 'type-1',
			due: new DateTimeImmutable('2026-03-01'),
			title: 'Kwartaalrapportage',
			uid: 'alice',
			recurrence: 'quarterly',
			until: '2026-12-31'
		);

		// Two occurrences still to come before the end date.
		$this->assertFalse(
			$this->document->isSpent(
				document: $built,
				firedCount: 2,
				today: new DateTimeImmutable('2026-06-01')
			)
		);

		// The next one lands in March 2027, past the end date.
		$this->assertTrue(
			$this->document->isSpent(
				document: $built,
				firedCount: 4,
				today: new DateTimeImmutable('2026-12-01')
			)
		);
	}//end testADatedSeriesIsSpentWhenItsEndHasPassed()

	/**
	 * A series with neither end runs until somebody stops it.
	 *
	 * @return void
	 */
	public function testAnOpenEndedSeriesIsNeverSpent(): void {
		$built = $this->document->build(
			caseId: 'case-1',
			caseTypeId: 'type-1',
			due: new DateTimeImmutable('2026-03-01'),
			title: 'Vergunningcontrole',
			uid: 'alice',
			recurrence: 'yearly'
		);

		$this->assertFalse(
			$this->document->isSpent(
				document: $built,
				firedCount: 40,
				today: new DateTimeImmutable('2066-03-02')
			)
		);
	}//end testAnOpenEndedSeriesIsNeverSpent()

	/**
	 * The marker carries the series and the next occurrence.
	 *
	 * @return void
	 */
	public function testTheMarkerCarriesTheSeriesAndItsNextOccurrence(): void {
		$built = $this->document->build(
			caseId: 'case-1',
			caseTypeId: 'type-1',
			due: new DateTimeImmutable('2026-08-31'),
			title: 'Maandelijkse controle',
			uid: 'alice',
			recurrence: 'monthly',
			count: 3
		);

		$marker = $this->document->markerOf(
			nodes: $built['nodes'],
			from: new DateTimeImmutable('2026-09-01')
		);

		$this->assertSame('case-1', $marker['case']);
		$this->assertSame('monthly', $marker['recurrence']);
		$this->assertSame(3, $marker['count']);
		$this->assertSame('2026-09-30', $marker['date']);
	}//end testTheMarkerCarriesTheSeriesAndItsNextOccurrence()

	/**
	 * A flow written before series existed still reads as a single follow-up.
	 *
	 * @return void
	 */
	public function testAFlowWithoutASeriesBlockReadsAsSingle(): void {
		$nodes = [
			[
				'id' => 'when',
				'type' => 'openregister.trigger-schedule',
				'config' => ['cron' => '0 6 15 10 *', 'runAs' => 'alice'],
			],
			[
				'id' => 'create',
				'type' => 'dossiq.createSubCase',
				'config' => [
					'caseType' => 'type-1',
					'title' => 'Controle',
					'relatedCases' => ['case-1'],
				],
			],
		];

		$marker = $this->document->markerOf(nodes: $nodes);
		$this->assertSame('none', $marker['recurrence']);
		$this->assertMatchesRegularExpression('/^\d{4}-10-15$/', $marker['date']);

		$this->assertTrue(
			$this->document->isSpent(
				document: ['nodes' => $nodes],
				firedCount: 1,
				today: new DateTimeImmutable('2026-10-15')
			)
		);
	}//end testAFlowWithoutASeriesBlockReadsAsSingle()

	/**
	 * The series flow stamps its own id onto every case it creates.
	 *
	 * @return void
	 */
	public function testOccurrencesCarryTheSeriesAsTheirHandoffSource(): void {
		$built = $this->document->build(
			caseId: 'case-1',
			caseTypeId: 'type-1',
			due: new DateTimeImmutable('2026-08-31'),
			title: 'Controle',
			uid: 'alice',
			recurrence: 'monthly'
		);

		$nodes = $this->document->withSeriesSource(document: $built, flowId: 'flow-9');
		$this->assertSame(
			'planned-series:flow-9',
			$nodes[1]['config']['handoffSource']
		);
	}//end testOccurrencesCarryTheSeriesAsTheirHandoffSource()

	/**
	 * A recurrence nobody offers is refused rather than silently ignored.
	 *
	 * @return void
	 */
	public function testAnUnknownRecurrenceIsRefused(): void {
		$this->assertSame('none', $this->document->recurrenceOf(''));
		$this->assertSame('quarterly', $this->document->recurrenceOf('quarterly'));

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('invalid_recurrence');
		$this->document->recurrenceOf('every other tuesday');
	}//end testAnUnknownRecurrenceIsRefused()

	/**
	 * A series may end on a date or after a count, never both.
	 *
	 * @return void
	 */
	public function testAnEndIsADateOrACountButNotBoth(): void {
		$this->assertSame(
			['until' => '2026-12-31', 'count' => 0],
			$this->document->endOf(recurrence: 'monthly', until: '2026-12-31', count: 0)
		);
		$this->assertSame(
			['until' => '', 'count' => 3],
			$this->document->endOf(recurrence: 'monthly', until: '', count: 3)
		);
		$this->assertSame(
			['until' => '', 'count' => 0],
			$this->document->endOf(recurrence: 'none', until: '2026-12-31', count: 3)
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('invalid_end');
		$this->document->endOf(recurrence: 'monthly', until: '2026-12-31', count: 3);
	}//end testAnEndIsADateOrACountButNotBoth()
}//end class
