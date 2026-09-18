<?php

/**
 * Several dated events inside one case, each with its own owner.
 *
 * 🔴 THE ASSERTION THAT CARRIES ROW 2.45 is the ORDER. An address that
 * generates a report in March, another in June and a third in September is one
 * case with three incidents, and a list ordered by creation puts March under
 * June whenever March was typed up late. The pattern is the whole reason the
 * case is worth keeping open, and a list in the wrong order hides it.
 *
 * 🔴 AND THAT A HAND-OFF LEAVES THE CASE ALONE. The case sits with the area
 * handler while one report inside it is worked by an inspector. A hand-off that
 * moved the case would take the other two reports with it, which is exactly the
 * behaviour that makes people open three cases instead.
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

use OCA\Dossiq\Service\IncidentService;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Recording, ordering, counting and handing over.
 *
 * @covers \OCA\Dossiq\Service\IncidentService
 */
class IncidentServiceTest extends TestCase {

	/**
	 * Everything the fake store was asked to write.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $writes = [];

	/**
	 * Reset the log.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->writes = [];
	}//end setUp()

	/**
	 * Build the service over an in-memory store.
	 *
	 * @param array<int, array<string, mixed>> $stored      The incidents it holds.
	 * @param bool                             $ignoreFilter Whether the store honours `case`.
	 *
	 * @return IncidentService The service under test.
	 */
	private function makeService(array $stored, bool $ignoreFilter = false): IncidentService {
		$writes = &$this->writes;

		$objectService = new class($stored, $ignoreFilter, $writes) {
			/**
			 * @param array<int, array<string, mixed>> $stored       The rows.
			 * @param bool                             $ignoreFilter Whether to filter.
			 * @param array<int, array<string, mixed>> $writes       Write log.
			 */
			public function __construct(
				private array $stored,
				private bool $ignoreFilter,
				private array &$writes,
			) {
			}//end __construct()

			/**
			 * Answer the rows, filtered or not.
			 *
			 * @param array<string, mixed> $params The query.
			 *
			 * @return array<string, mixed> The paginated answer.
			 */
			public function findAll(array $params = []): array {
				if ($this->ignoreFilter === true) {
					return ['results' => $this->stored];
				}

				$wanted = (string)($params['filters']['case'] ?? '');

				return [
					'results' => array_values(
						array_filter(
							$this->stored,
							static fn (array $row): bool => ($row['case'] ?? '') === $wanted
						)
					),
				];
			}//end findAll()

			/**
			 * Find one row by id.
			 *
			 * @param string $id       The id.
			 * @param mixed  $register The register (ignored).
			 * @param mixed  $schema   The schema (ignored).
			 *
			 * @return array<string, mixed>|null The row.
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): ?array {
				foreach ($this->stored as $row) {
					if (($row['id'] ?? '') === $id) {
						return $row;
					}
				}

				return null;
			}//end find()

			/**
			 * Record a write.
			 *
			 * @param array<string, mixed> $object   The row.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string               $uuid     The id.
			 *
			 * @return array<string, mixed> The saved row.
			 */
			public function saveObject(
				array $object,
				string $register = '',
				string $schema = '',
				string $uuid = '',
			): array {
				$this->writes[] = $object;

				return array_merge($object, ['id' => ($uuid !== '' ? $uuid : 'incident-new')]);
			}//end saveObject()
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'register' ? 'dossiq' : $default)
		);

		return new IncidentService($settings);
	}//end makeService()

	/**
	 * Three reports on one address, listed by when they happened.
	 *
	 * @return void
	 */
	public function testThreeReportsAreListedByWhenTheyHappened(): void {
		// The June report was typed up first; March was recorded three weeks
		// late. A list ordered by creation would put them the wrong way round.
		$service = $this->makeService(
			[
				['id' => 'i-june', 'case' => 'case-a', 'eventDate' => '2026-06-04T10:00:00+02:00'],
				['id' => 'i-march', 'case' => 'case-a', 'eventDate' => '2026-03-11T09:00:00+01:00'],
				['id' => 'i-sept', 'case' => 'case-a', 'eventDate' => '2026-09-02T14:00:00+02:00'],
			]
		);

		$this->assertSame(
			['i-march', 'i-june', 'i-sept'],
			array_column($service->onCase(caseId: 'case-a'), 'id')
		);
	}//end testThreeReportsAreListedByWhenTheyHappened()

	/**
	 * Only this case's incidents are listed.
	 *
	 * @return void
	 */
	public function testAStoreThatIgnoresTheFilterDoesNotLeak(): void {
		// A store that does not recognise a filter answers the whole register,
		// confidently and with no error. This list is rendered on a case page
		// as that case's own history.
		$service = $this->makeService(
			[
				['id' => 'mine', 'case' => 'case-a', 'eventDate' => '2026-03-11T09:00:00+01:00'],
				['id' => 'theirs', 'case' => 'case-b', 'eventDate' => '2026-03-12T09:00:00+01:00'],
			],
			ignoreFilter: true
		);

		$this->assertSame(['mine'], array_column($service->onCase(caseId: 'case-a'), 'id'));
	}//end testAStoreThatIgnoresTheFilterDoesNotLeak()

	/**
	 * A hand-off writes the incident's own assignee and nothing else.
	 *
	 * @return void
	 */
	public function testAHandOverLeavesTheCaseAlone(): void {
		$service = $this->makeService(
			[
				[
					'id' => 'i-june',
					'case' => 'case-a',
					'assignee' => 'area-handler',
					'eventDate' => '2026-06-04T10:00:00+02:00',
				],
			]
		);

		$handed = $service->handOver(incidentId: 'i-june', assignee: 'inspector');

		$this->assertSame('inspector', $handed['assignee']);
		// 🔴 THE CASE IS UNTOUCHED. The incident still names the same case, and
		// nothing was written to the case schema at all.
		$this->assertSame('case-a', $handed['case']);
		$this->assertCount(1, $this->writes);
		$this->assertSame('case-a', $this->writes[0]['case']);
	}//end testAHandOverLeavesTheCaseAlone()

	/**
	 * The recording moment is stamped here, not taken from the caller.
	 *
	 * @return void
	 */
	public function testTheRecordingMomentIsStampedHere(): void {
		$service = $this->makeService([]);

		$service->record(
			caseId: 'case-a',
			fields: [
				'eventDate' => '2026-03-11T09:00:00+01:00',
				// A caller-supplied recordedAt would erase the delay, which is
				// the one thing worth reading about a late report.
				'recordedAt' => '1999-01-01T00:00:00+01:00',
				'description' => 'Geluidsoverlast',
			]
		);

		$this->assertCount(1, $this->writes);
		$this->assertNotSame('1999-01-01T00:00:00+01:00', $this->writes[0]['recordedAt']);
		$this->assertSame('case-a', $this->writes[0]['case']);
		$this->assertSame('open', $this->writes[0]['state'], 'a new incident opens');
	}//end testTheRecordingMomentIsStampedHere()

	/**
	 * An unknown state falls back to open rather than being stored.
	 *
	 * @return void
	 */
	public function testAnUnknownStateFallsBackToOpen(): void {
		$this->makeService([])->record(
			caseId: 'case-a',
			fields: ['eventDate' => '2026-03-11T09:00:00+01:00', 'state' => 'whatever']
		);

		$this->assertSame('open', $this->writes[0]['state']);

		// The control: a state the schema knows is kept.
		$this->writes = [];
		$this->makeService([])->record(
			caseId: 'case-a',
			fields: ['eventDate' => '2026-03-11T09:00:00+01:00', 'state' => 'afgehandeld']
		);
		$this->assertSame('afgehandeld', $this->writes[0]['state']);
	}//end testAnUnknownStateFallsBackToOpen()

	/**
	 * A late recording shows its delay in whole days.
	 *
	 * @return void
	 */
	public function testALateRecordingShowsItsDelay(): void {
		$service = $this->makeService([]);

		$this->assertSame(
			21,
			$service->recordingDelayOf(
				incident: [
					'eventDate' => '2026-03-11T09:00:00+01:00',
					'recordedAt' => '2026-04-01T09:00:00+02:00',
				]
			)
		);

		// Same day is no delay, which is the ordinary case and must not render
		// as "0 days late" by accident of a different code path.
		$this->assertSame(
			0,
			$service->recordingDelayOf(
				incident: [
					'eventDate' => '2026-03-11T09:00:00+01:00',
					'recordedAt' => '2026-03-11T17:00:00+01:00',
				]
			)
		);

		// An incident recorded before it happened is a clock problem, not a
		// negative number to render on a page.
		$this->assertSame(
			0,
			$service->recordingDelayOf(
				incident: [
					'eventDate' => '2026-03-11T09:00:00+01:00',
					'recordedAt' => '2026-03-01T09:00:00+01:00',
				]
			)
		);

		$this->assertNull($service->recordingDelayOf(incident: ['eventDate' => '2026-03-11']));
	}//end testALateRecordingShowsItsDelay()

	/**
	 * Open incidents are counted, closed ones are not.
	 *
	 * @return void
	 */
	public function testOpenIncidentsAreCounted(): void {
		$service = $this->makeService(
			[
				['id' => 'a', 'case' => 'case-a', 'eventDate' => '2026-03-11', 'state' => 'open'],
				['id' => 'b', 'case' => 'case-a', 'eventDate' => '2026-06-04', 'state' => 'in_behandeling'],
				['id' => 'c', 'case' => 'case-a', 'eventDate' => '2026-09-02', 'state' => 'afgehandeld'],
			]
		);

		$this->assertSame(2, $service->openCountOn(caseId: 'case-a'));
	}//end testOpenIncidentsAreCounted()

	/**
	 * An incident with no state recorded counts as open.
	 *
	 * @return void
	 */
	public function testAnIncidentWithNoStateCountsAsOpen(): void {
		$service = $this->makeService(
			[['id' => 'a', 'case' => 'case-a', 'eventDate' => '2026-03-11']]
		);

		// A report nobody has classified still needs somebody. Reading an
		// absent state as "done" would take it off the count that says so.
		$this->assertSame(1, $service->openCountOn(caseId: 'case-a'));
	}//end testAnIncidentWithNoStateCountsAsOpen()
}//end class
