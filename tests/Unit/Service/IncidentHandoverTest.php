<?php

/**
 * Handing one report to somebody without moving the case.
 *
 * The whole of REQ-INC-02 is a NEGATIVE: the case must not move. So every test
 * here asserts the case's own seat after the hand-off, not only the incident's.
 * A test that checked the incident alone would pass on an implementation that
 * re-seated the case as well, which is exactly the defect: the area handler
 * would lose the address because an inspector took one report on it.
 *
 * ⚠️ NO MUTATION IS CLAIMED FOR THE NEGATIVE, and saying so is better than
 * implying one. The guarantee that the case does not move is STRUCTURAL:
 * `IncidentService` has no case writer at all, so making it re-seat the case
 * means adding a method rather than flipping a condition, and a mutation that
 * has to write new code is not a mutation. What this test does is fail the day
 * somebody adds that method, which is the moment that matters. The mutations
 * that ARE claimed here live in IncidentServiceTest and IncidentQueueCountTest.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Incidents\IncidentService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One report moves to an inspector, and the case does not.
 *
 * @covers \OCA\Dossiq\Service\Incidents\IncidentService::assign
 * @covers \OCA\Dossiq\Service\Incidents\IncidentService::ownedBy
 * @uses \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class IncidentHandoverTest extends TestCase {

	/**
	 * The store the service reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * A case held by an area handler, with two open reports on it.
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
	 * The report names the inspector; the case still names the area handler.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-hands-off-without-moving-the-case-req-inc-02
	 */
	public function testAssigningAReportLeavesTheCaseWhereItIs(): void {
		$incidents = $this->incidents();
		$first = $incidents->record(caseId: 'case-1', eventDate: '2026-03-21T09:00:00+01:00', description: 'Maart');
		$incidents->record(caseId: 'case-1', eventDate: '2026-06-14T09:00:00+02:00', description: 'Juni');

		$assigned = $incidents->assign(incidentId: (string)$first['id'], assignee: 'inspecteur');

		self::assertSame('inspecteur', $assigned['assignee']);
		self::assertSame(
			IncidentService::STATE_WORKING,
			$assigned['state'],
			'A report somebody is working is not an untouched one.',
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
	 * A settled report is not put back to work by handing it on.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-hands-off-without-moving-the-case-req-inc-02
	 */
	public function testHandingOnASettledReportDoesNotReopenIt(): void {
		$incidents = $this->incidents();
		$recorded = $incidents->record(caseId: 'case-1', eventDate: '2026-03-21T09:00:00+01:00', description: 'Maart');
		$incidents->settle(incidentId: (string)$recorded['id'], outcome: 'Waarschuwing gegeven');

		$assigned = $incidents->assign(incidentId: (string)$recorded['id'], assignee: 'inspecteur');

		self::assertSame(IncidentService::STATE_SETTLED, $assigned['state']);
		self::assertSame('inspecteur', $assigned['assignee'], 'It still changes hands, it just does not reopen.');
	}//end testHandingOnASettledReportDoesNotReopenIt()

	/**
	 * A person's work list holds the reports they own, oldest event first.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-hands-off-without-moving-the-case-req-inc-02
	 */
	public function testTheInspectorSeesTheirOwnReports(): void {
		$incidents = $this->incidents();
		$june = $incidents->record(
			caseId: 'case-1',
			eventDate: '2026-06-14T09:00:00+02:00',
			description: 'Juni',
			assignee: 'inspecteur',
		);
		$march = $incidents->record(
			caseId: 'case-1',
			eventDate: '2026-03-21T09:00:00+01:00',
			description: 'Maart',
			assignee: 'inspecteur',
		);
		$incidents->record(
			caseId: 'case-1',
			eventDate: '2026-09-02T09:00:00+02:00',
			description: 'September',
			assignee: 'iemand anders',
		);

		$mine = $incidents->ownedBy(userId: 'inspecteur');

		self::assertSame(['Maart', 'Juni'], array_column($mine, 'description'));
		self::assertNotSame((string)$june['id'], (string)$mine[0]['id'], 'Oldest event first, not oldest record.');
		self::assertSame((string)$march['id'], (string)$mine[0]['id']);

		$incidents->settle(incidentId: (string)$march['id'], outcome: 'Afgehandeld');
		self::assertSame(
			['Juni'],
			array_column($incidents->ownedBy(userId: 'inspecteur'), 'description'),
			'A settled report leaves the list, or the list stops meaning anything.',
		);
	}//end testTheInspectorSeesTheirOwnReports()

	/**
	 * Handing on a report nobody recorded is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-hands-off-without-moving-the-case-req-inc-02
	 */
	public function testAnUnknownReportIsRefused(): void {
		$this->expectException(RefusedException::class);

		$this->incidents()->assign(incidentId: 'incident-404', assignee: 'inspecteur');
	}//end testAnUnknownReportIsRefused()

	/**
	 * The service under test.
	 *
	 * @return IncidentService The service.
	 */
	private function incidents(): IncidentService {
		return new IncidentService(
			settingsService: $this->settings(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end incidents()

	/**
	 * A settings service that answers the in-memory store and the slugs it holds.
	 *
	 * @return SettingsService The settings service.
	 */
	private function settings(): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				$map = [
					'register' => 'dossiq',
					'case_schema' => 'case',
					'incident_schema' => 'incident',
				];

				return ($map[$key] ?? $default);
			}
		);

		return $settings;
	}//end settings()
}//end class
