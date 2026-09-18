<?php

/**
 * The dated reports inside a case.
 *
 * The property worth protecting is the ORDER. A list sorted on the creation
 * moment reads the pattern backwards the first time somebody writes up an old
 * report, and a case whose reports read in the wrong order is a case whose
 * history says the opposite of what happened. So the fixture records the March
 * report LAST, and the assertion is about the order that comes back.
 *
 * MUTATION-CHECKED 2026-09-18: sorting `on()` by `recordedAt` instead of
 * `eventDate` reddens testTheReportsReadInEventOrder on the order assertion,
 * and it is the only assertion here that notices, which is why the fixture
 * records them out of order rather than in it. Restored after.
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
 * Recording, ordering, the recording delay, and settling.
 *
 * @covers \OCA\Dossiq\Service\Incidents\IncidentService
 * @uses \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class IncidentServiceTest extends TestCase {

	/**
	 * The store the service reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $store;

	/**
	 * One case about an address.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
		$this->store->seed(schema: 'case', uuid: 'case-1', row: ['title' => 'Kerkstraat 12']);
	}//end setUp()

	/**
	 * Three reports on one address, read back in the order they happened.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-dated-incidents-with-their-own-owners-req-inc-01
	 */
	public function testTheReportsReadInEventOrder(): void {
		$incidents = $this->incidents();

		// RECORDED OUT OF ORDER ON PURPOSE. Recorded in order, a list sorted on
		// the creation moment would pass this test and still read every late
		// report in the wrong place.
		$incidents->record(caseId: 'case-1', eventDate: '2026-09-02T09:00:00+02:00', description: 'September', reporter: 'buurman');
		$incidents->record(caseId: 'case-1', eventDate: '2026-06-14T09:00:00+02:00', description: 'Juni', reporter: 'wijkagent');
		$incidents->record(caseId: 'case-1', eventDate: '2026-03-21T09:00:00+01:00', description: 'Maart', reporter: 'melder');

		$listed = $incidents->on(caseId: 'case-1');

		self::assertSame(
			['Maart', 'Juni', 'September'],
			array_column($listed, 'description'),
			'A report written up late belongs where it happened, not where it was typed.',
		);
		self::assertSame(
			['melder', 'wijkagent', 'buurman'],
			array_column($listed, 'reporter'),
			'And each one carries its own reporter.',
		);
	}//end testTheReportsReadInEventOrder()

	/**
	 * A late recording says how late it was.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-dated-incidents-with-their-own-owners-req-inc-01
	 */
	public function testALateRecordingShowsItsDelay(): void {
		$incidents = $this->incidents();

		$delay = $incidents->recordingDelayDays(
			incident: [
				'eventDate' => '2026-03-01T09:00:00+01:00',
				'recordedAt' => '2026-03-22T09:00:00+01:00',
			]
		);

		self::assertSame(21, $delay, 'Three weeks, and the reader can see it rather than working it out.');
		self::assertSame(
			0,
			$incidents->recordingDelayDays(
				incident: [
					'eventDate' => '2026-03-22T09:00:00+01:00',
					'recordedAt' => '2026-03-01T09:00:00+01:00',
				]
			),
			'A record written before the event is a clock out of step, not a report from the future.',
		);
		self::assertNull($incidents->recordingDelayDays(incident: ['eventDate' => '2026-03-01']));
	}//end testALateRecordingShowsItsDelay()

	/**
	 * A report arrives open, and is settled with its outcome.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-dated-incidents-with-their-own-owners-req-inc-01
	 */
	public function testAReportIsOpenUntilItIsSettled(): void {
		$incidents = $this->incidents();
		$recorded = $incidents->record(
			caseId: 'case-1',
			eventDate: '2026-03-21T09:00:00+01:00',
			description: 'Geluidsoverlast',
		);

		self::assertSame(IncidentService::STATE_OPEN, $recorded['state']);
		self::assertSame('', $recorded['outcome'], 'An outcome before there is one would be an answer nobody gave.');

		$settled = $incidents->settle(incidentId: (string)$recorded['id'], outcome: 'Waarschuwing gegeven');

		self::assertSame(IncidentService::STATE_SETTLED, $settled['state']);
		self::assertSame('Waarschuwing gegeven', $settled['outcome']);
		self::assertNotSame('', ($settled['settledAt'] ?? ''));
	}//end testAReportIsOpenUntilItIsSettled()

	/**
	 * Settling with no outcome is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-dated-incidents-with-their-own-owners-req-inc-01
	 */
	public function testSettlingWithNoOutcomeIsRefused(): void {
		$incidents = $this->incidents();
		$recorded = $incidents->record(
			caseId: 'case-1',
			eventDate: '2026-03-21T09:00:00+01:00',
			description: 'Geluidsoverlast',
		);

		$this->expectException(RefusedException::class);
		$incidents->settle(incidentId: (string)$recorded['id'], outcome: '   ');
	}//end testSettlingWithNoOutcomeIsRefused()

	/**
	 * A report with no date or no description is refused.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-dated-incidents-with-their-own-owners-req-inc-01
	 */
	public function testAnIncompleteReportIsRefused(): void {
		$this->expectException(RefusedException::class);

		$this->incidents()->record(caseId: 'case-1', eventDate: '', description: 'Zonder datum');
	}//end testAnIncompleteReportIsRefused()

	/**
	 * An incident carries no term and no decision of its own.
	 *
	 * The assertion is over the SCHEMA rather than over a row, because the
	 * defect this guards against is somebody adding a `deadline` property to
	 * the incident later, which would give every report a statutory clock
	 * nobody owes.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-dated-incidents-with-their-own-owners-req-inc-01
	 */
	public function testAnIncidentIsNotADeelzaak(): void {
		$register = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'),
			true
		);
		$properties = array_keys($register['components']['schemas']['incident']['properties']);

		foreach (['deadline', 'decision', 'identifier', 'caseNumber', 'statutoryTerm'] as $forbidden) {
			self::assertNotContains(
				$forbidden,
				$properties,
				'An incident with a ' . $forbidden . ' is a deelzaak wearing another name.',
			);
		}
	}//end testAnIncidentIsNotADeelzaak()

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
