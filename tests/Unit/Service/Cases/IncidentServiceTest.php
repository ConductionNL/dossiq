<?php

/**
 * Several dated reports inside one case, in the order they happened.
 *
 * 🔴 THE ORDER IS THE EVENT DATE AND NOT THE RECORDING MOMENT. A report
 * recorded three weeks late belongs where it HAPPENED, and sorting by creation
 * puts it at the top of the list and quietly rewrites the sequence the case is
 * about. The fixture is deliberately built so the two orders DIFFER: a list
 * sorted either way would pass on data where they agree.
 *
 * 🔴 AN INCIDENT WITH NO STATE COUNTS AS OPEN. Counting it as finished lets a
 * work list report a clean desk that is not, which is the one number a
 * teamleider acts on.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\IncidentRecord;
use PHPUnit\Framework\TestCase;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;

/**
 * The incidents of a case.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class IncidentServiceTest extends TestCase {
	use MakesCaseDateNormaliser;

	private IncidentRecord $incidents;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->incidents = new IncidentRecord(dates: $this->caseDates());
	}//end setUp()

	/**
	 * Three reports on one address, the middle one recorded last.
	 *
	 * @return array<int, array<string, mixed>> The incidents.
	 */
	private function threeReports(): array {
		return [
			['id' => 'march', 'eventDate' => '2026-03-04', 'recordedAt' => '2026-03-04T09:00:00+01:00', 'state' => 'afgehandeld'],
			// Recorded LAST and happened SECOND: the whole point of D-6.
			['id' => 'june', 'eventDate' => '2026-06-11', 'recordedAt' => '2026-09-30T16:00:00+02:00', 'state' => 'open'],
			['id' => 'september', 'eventDate' => '2026-09-02', 'recordedAt' => '2026-09-02T11:00:00+02:00'],
		];
	}//end threeReports()

	/**
	 * The list reads as the sequence of events.
	 *
	 * @return void
	 */
	public function testTheListIsOrderedByWhenThingsHappened(): void {
		$ordered = $this->incidents->inEventOrder(incidents: $this->threeReports());

		$this->assertSame(['march', 'june', 'september'], array_column($ordered, 'id'));
	}//end testTheListIsOrderedByWhenThingsHappened()

	/**
	 * Two reports on one day keep the order they were written in, which is the
	 * only order anybody can check afterwards.
	 *
	 * @return void
	 */
	public function testTwoReportsOnOneDayKeepTheirRecordingOrder(): void {
		$ordered = $this->incidents->inEventOrder([
			['id' => 'second', 'eventDate' => '2026-03-04', 'recordedAt' => '2026-03-04T15:00:00+01:00'],
			['id' => 'first', 'eventDate' => '2026-03-04', 'recordedAt' => '2026-03-04T09:00:00+01:00'],
		]);

		$this->assertSame(['first', 'second'], array_column($ordered, 'id'));
	}//end testTwoReportsOnOneDayKeepTheirRecordingOrder()

	/**
	 * An incident nobody dated sorts last rather than heading the sequence.
	 *
	 * @return void
	 */
	public function testAnUndatedIncidentDoesNotHeadTheSequence(): void {
		$ordered = $this->incidents->inEventOrder([
			['id' => 'undated'],
			['id' => 'march', 'eventDate' => '2026-03-04'],
		]);

		$this->assertSame(['march', 'undated'], array_column($ordered, 'id'));
	}//end testAnUndatedIncidentDoesNotHeadTheSequence()

	/**
	 * The delay between the event and its recording is visible.
	 *
	 * @return void
	 */
	public function testTheRecordingDelayIsAvailableRatherThanHidden(): void {
		$june = $this->threeReports()[1];

		$this->assertSame(111, $this->incidents->recordingDelayDays(incident: $june));
		$this->assertSame(0, $this->incidents->recordingDelayDays(['eventDate' => '2026-03-04', 'recordedAt' => '2026-03-04T23:00:00+01:00']));
	}//end testTheRecordingDelayIsAvailableRatherThanHidden()

	/**
	 * A recording before the event is a typo, not a prediction.
	 *
	 * @return void
	 */
	public function testARecordingBeforeTheEventReadsAsNoDelay(): void {
		$this->assertSame(
			0,
			$this->incidents->recordingDelayDays(['eventDate' => '2026-06-11', 'recordedAt' => '2026-06-01T09:00:00+02:00'])
		);
	}//end testARecordingBeforeTheEventReadsAsNoDelay()

	/**
	 * A missing moment has no delay to report.
	 *
	 * @return void
	 */
	public function testAMissingMomentHasNoDelay(): void {
		$this->assertNull($this->incidents->recordingDelayDays(['eventDate' => '2026-06-11']));
		$this->assertNull($this->incidents->recordingDelayDays([]));
	}//end testAMissingMomentHasNoDelay()

	/**
	 * The open count is what a work list shows, and an unstated state counts
	 * as open.
	 *
	 * @return void
	 */
	public function testAnIncidentWithNoStateCountsAsOpen(): void {
		// march is finished, june is open, september says nothing.
		$this->assertSame(2, $this->incidents->openCount(incidents: $this->threeReports()));
		$this->assertSame(0, $this->incidents->openCount([['state' => 'afgehandeld']]));
		$this->assertSame(1, $this->incidents->openCount([['state' => 'in-behandeling']]));
	}//end testAnIncidentWithNoStateCountsAsOpen()
}//end class
