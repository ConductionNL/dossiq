<?php

/**
 * Writing and reading incidents, against a real register.
 *
 * `IncidentRecord` shipped pure and has its own suite, so this one does NOT
 * re-test the ordering rule, the delay arithmetic or the hand-off shape. It
 * watches what the record could not: whether anything was written at all, and
 * whether a hand-off left the case alone.
 *
 * That second one is the whole of the requirement and it is a NEGATIVE, so
 * every hand-off test reads the case's own seat afterwards. A test that checked
 * the incident alone would pass on an implementation that re-seated the case,
 * which is the defect: the area handler loses the address because an inspector
 * took one report on it.
 *
 * MUTATION-CHECKED 2026-09-18: making `on()` return the rows unordered, by
 * dropping the `inEventOrder()` call, reddens testTheReportsReadInEventOrder;
 * making `ownedBy()` skip its open filter reddens
 * testASettledReportLeavesTheInspectorsList. Restored after.
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
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Cases\IncidentRecord;
use OCA\Dossiq\Service\Cases\IncidentStore;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;

/**
 * Recording, listing, handing on, settling and counting.
 *
 * @covers \OCA\Dossiq\Service\Cases\IncidentStore
 * @uses \OCA\Dossiq\Service\Cases\IncidentRecord
 * @uses \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class IncidentStoreTest extends TestCase {
	use MakesCaseDateNormaliser;


	/**
	 * The store the service reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * A case held by an area handler.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['title' => 'Kerkstraat 12', 'assignee' => 'wijkbeheerder', 'assignedGroup' => 'toezicht'],
		);
	}//end setUp()

	/**
	 * Three reports on one address, read back in the order they happened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	public function testTheReportsReadInEventOrder(): void {
		$incidents = $this->incidents();

		// RECORDED OUT OF ORDER ON PURPOSE. Recorded in order, a list that did
		// not sort at all would pass this and still read every late report in
		// the wrong place.
		$incidents->record(caseId: 'case-1', eventDate: '2026-09-02T09:00:00+02:00', description: 'September', reporter: 'buurman');
		$incidents->record(caseId: 'case-1', eventDate: '2026-06-14T09:00:00+02:00', description: 'Juni', reporter: 'wijkagent');
		$incidents->record(caseId: 'case-1', eventDate: '2026-03-21T09:00:00+01:00', description: 'Maart', reporter: 'melder');

		$listed = $incidents->on(caseId: 'case-1');

		self::assertSame(['Maart', 'Juni', 'September'], array_column($listed, 'description'));
		self::assertSame(['melder', 'wijkagent', 'buurman'], array_column($listed, 'reporter'));
		// The delay is the record's arithmetic and has its own suite there. What
		// this asserts is only that the store PASSES IT THROUGH, so a caller
		// holding the store does not have to hold the record as well.
		self::assertSame(
			(new IncidentRecord(dates: $this->caseDates()))->recordingDelayDays(incident: $listed[0]),
			$incidents->recordingDelayDays(incident: $listed[0]),
		);
	}//end testTheReportsReadInEventOrder()

	/**
	 * A report arrives open, and is settled with its outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	public function testAReportIsOpenUntilItIsSettled(): void {
		$incidents = $this->incidents();
		$recorded = $incidents->record(
			caseId: 'case-1',
			eventDate: '2026-03-21T09:00:00+01:00',
			description: 'Geluidsoverlast',
		);

		self::assertSame('open', $recorded['state']);
		self::assertSame('', $recorded['outcome'], 'An outcome before there is one would be an answer nobody gave.');

		$settled = $incidents->settle(incidentId: (string)$recorded['id'], outcome: 'Waarschuwing gegeven');

		self::assertSame('afgehandeld', $settled['state']);
		self::assertSame('Waarschuwing gegeven', $settled['outcome']);
	}//end testAReportIsOpenUntilItIsSettled()

	/**
	 * An incomplete report, and a settlement with no outcome, are refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	public function testAnIncompleteReportIsRefused(): void {
		$incidents = $this->incidents();

		try {
			$incidents->record(caseId: 'case-1', eventDate: '', description: 'Zonder datum');
			self::fail('A report with no date must be refused.');
		} catch (RefusedException $e) {
			self::assertSame(IncidentStore::INCOMPLETE, $e->getRule());
		}

		$recorded = $incidents->record(
			caseId: 'case-1',
			eventDate: '2026-03-21T09:00:00+01:00',
			description: 'Geluidsoverlast',
		);

		$this->expectException(RefusedException::class);
		$incidents->settle(incidentId: (string)$recorded['id'], outcome: '  ');
	}//end testAnIncompleteReportIsRefused()

	/**
	 * The report names the inspector; the case still names the area handler.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-carries-its-own-hand-off-req-cm-54
	 */
	public function testAssigningAReportLeavesTheCaseWhereItIs(): void {
		$incidents = $this->incidents();
		$first = $incidents->record(caseId: 'case-1', eventDate: '2026-03-21T09:00:00+01:00', description: 'Maart');

		$assigned = $incidents->assign(incidentId: (string)$first['id'], assignee: 'inspecteur');

		self::assertSame('inspecteur', $assigned['assignee']);
		self::assertSame(
			'in-behandeling',
			$assigned['state'],
			'Taking a report on is what starts work on it; the record decides that, not this store.',
		);

		$case = $this->store->row(schema: 'case', uuid: 'case-1');
		self::assertSame(
			'wijkbeheerder',
			$case['assignee'],
			'The area handler keeps the address; an inspector took one report on it, not the case.',
		);
		self::assertSame('toezicht', $case['assignedGroup']);
	}//end testAssigningAReportLeavesTheCaseWhereItIs()

	/**
	 * A settled report leaves the inspector's list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-carries-its-own-hand-off-req-cm-54
	 */
	public function testASettledReportLeavesTheInspectorsList(): void {
		$incidents = $this->incidents();
		$march = $incidents->record(
			caseId: 'case-1',
			eventDate: '2026-03-21T09:00:00+01:00',
			description: 'Maart',
			assignee: 'inspecteur',
		);
		$incidents->record(
			caseId: 'case-1',
			eventDate: '2026-06-14T09:00:00+02:00',
			description: 'Juni',
			assignee: 'inspecteur',
		);
		$incidents->record(
			caseId: 'case-1',
			eventDate: '2026-09-02T09:00:00+02:00',
			description: 'September',
			assignee: 'iemand anders',
		);

		self::assertSame(
			['Maart', 'Juni'],
			array_column($incidents->ownedBy(userId: 'inspecteur'), 'description'),
			'Their own reports, oldest event first, and nobody else\'s.',
		);

		$incidents->settle(incidentId: (string)$march['id'], outcome: 'Afgehandeld');

		self::assertSame(
			['Juni'],
			array_column($incidents->ownedBy(userId: 'inspecteur'), 'description'),
			'A settled report leaves the list, or the list stops meaning anything.',
		);
	}//end testASettledReportLeavesTheInspectorsList()

	/**
	 * Five cases, nine open reports between them, counted per case.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-carries-its-own-hand-off-req-cm-54
	 */
	public function testFiveCasesHoldNineOpenReports(): void {
		$spread = ['case-1' => 1, 'case-2' => 2, 'case-3' => 2, 'case-4' => 3, 'case-5' => 1];
		$n = 0;
		foreach ($spread as $caseId => $count) {
			for ($i = 0; $i < $count; $i++) {
				$n++;
				$this->store->seed(
					schema: 'caseIncident',
					uuid: 'incident-' . $n,
					row: ['case' => $caseId, 'state' => 'open', 'eventDate' => '2026-03-01', 'description' => 'Melding ' . $n],
				);
			}
		}

		$this->store->seed(
			schema: 'caseIncident',
			uuid: 'settled-1',
			row: ['case' => 'case-6', 'state' => 'afgehandeld', 'eventDate' => '2026-03-01'],
		);

		$counts = $this->incidents()->openCountsFor(caseIds: array_merge(array_keys($spread), ['case-6']));

		self::assertSame($spread, $counts, 'Per case, because a total cannot be taken apart again.');
		self::assertSame(9, array_sum($counts));
		self::assertArrayNotHasKey(
			'case-6',
			$counts,
			'A zero in the answer would put an idle case on a work list.',
		);
	}//end testFiveCasesHoldNineOpenReports()

	/**
	 * A report somebody is working still counts as open.
	 *
	 * The rule is `IncidentRecord::OPEN_STATES`, and this asserts that the
	 * store asks it rather than keeping a list of its own.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-carries-its-own-hand-off-req-cm-54
	 */
	public function testAReportSomebodyIsWorkingStillCounts(): void {
		$this->store->seed(
			schema: 'caseIncident',
			uuid: 'working-1',
			row: ['case' => 'case-1', 'state' => 'in-behandeling', 'eventDate' => '2026-03-01'],
		);

		self::assertContains('in-behandeling', IncidentRecord::OPEN_STATES);
		self::assertSame(
			['case-1' => 1],
			$this->incidents()->openCountsFor(caseIds: ['case-1']),
			'Excluding it tells a coordinator the work is done while an inspector is out looking at it.',
		);
	}//end testAReportSomebodyIsWorkingStillCounts()

	/**
	 * The store under test, over the shipped record.
	 *
	 * @return IncidentStore The store.
	 */
	private function incidents(): IncidentStore {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = ['register' => 'dossiq', 'case_schema' => 'case', 'case_incident_schema' => 'caseIncident'];

				return ($map[$key] ?? $default);
			}
		);

		return new IncidentStore(
			settingsService: $settings,
			record: new IncidentRecord(dates: $this->caseDates()),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end incidents()
}//end class
