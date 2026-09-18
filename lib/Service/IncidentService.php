<?php

/**
 * The dated events inside one case.
 *
 * 🔴 AN INCIDENT IS NOT A DEELZAAK. A deelzaak has its own number, its own
 * term and its own decision. An incident has none of those: it is a dated
 * event inside one case with one term, and the reason to model it is that
 * several of them are what the case is about. One address that generates a
 * report in March, another in June and a third in September is one case with
 * three incidents, not three cases and not three paragraphs. Making an
 * incident a deelzaak would give every report a beslistermijn it does not
 * have.
 *
 * 🔴 IT OWNS ITS OWN HAND-OFF, AND NEVER THE CASE'S. The case sits with the
 * area handler while one report inside it is worked by an inspector. Handing
 * an incident over writes the incident's `assignee` and touches nothing on the
 * case: a hand-off that moved the case would take the other two reports with
 * it.
 *
 * 🔴 ORDERED BY WHEN IT HAPPENED, NOT WHEN IT WAS TYPED. A report recorded
 * three weeks late belongs where it happened. Both moments are stored, so the
 * delay is visible rather than hidden — which is the whole reason a pattern on
 * an address is worth reading.
 *
 * WHAT IS NOT HERE. Which state an incident may move to from which is the
 * workflow engine's question, and dossiq declares no second state machine:
 * `state` is a plain field and this service writes it without judging the
 * transition.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use Throwable;

/**
 * Records, lists and hands over the incidents on a case.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class IncidentService {

	/**
	 * The schema slug incidents live in.
	 *
	 * @var string
	 */
	public const SCHEMA = 'incident';

	/**
	 * The states an incident can be in, in the order they are worked.
	 *
	 * A list and not a machine: which move is allowed is the engine's.
	 *
	 * @var array<int, string>
	 */
	public const STATES = ['open', 'in_behandeling', 'afgehandeld'];

	/**
	 * The states that still need somebody.
	 *
	 * @var array<int, string>
	 */
	public const OPEN_STATES = ['open', 'in_behandeling'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Bridge to OpenRegister and the register.
	 */
	public function __construct(
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * Record one incident on a case.
	 *
	 * @param string               $caseId The case it happened inside.
	 * @param array<string, mixed> $fields The event date, reporter, description and the rest.
	 *
	 * @return array<string, mixed> The stored incident, or [] when it could not be written.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	public function record(string $caseId, array $fields): array {
		$context = $this->context();
		if ($context === null || trim($caseId) === '') {
			return [];
		}

		$payload = array_merge(
			$fields,
			[
				'case' => $caseId,
				// Stamped here and never taken from the caller: the delay
				// between the event and the recording is the thing worth
				// reading, and a caller-supplied recordedAt can erase it.
				'recordedAt' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
			]
		);

		if (in_array((string)($payload['state'] ?? ''), self::STATES, true) === false) {
			$payload['state'] = 'open';
		}

		try {
			return $this->arrayOf(
				row: $context['objects']->saveObject(
					object: $payload,
					register: $context['register'],
					schema: self::SCHEMA,
				)
			);
		} catch (Throwable) {
			return [];
		}
	}//end record()

	/**
	 * The incidents on a case, oldest event first.
	 *
	 * Sorted HERE rather than asked of the store, because the sort is the
	 * requirement: an incident list ordered by creation would put the report
	 * from March under the one from June whenever March was typed up late.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>> The incidents, in event-date order.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	public function onCase(string $caseId): array {
		$context = $this->context();
		if ($context === null || trim($caseId) === '') {
			return [];
		}

		try {
			$result = $context['objects']->findAll(
				[
					'filters' => [
						'register' => $context['register'],
						'schema' => self::SCHEMA,
						'case' => $caseId,
					],
				]
			);
		} catch (Throwable) {
			return [];
		}

		$rows = [];
		foreach ($this->rowsOf(result: $result) as $row) {
			$row = $this->arrayOf(row: $row);
			// Asked of the store and CHECKED here: a store that ignores an
			// unrecognised filter answers the whole register, and this list is
			// rendered on a case page as that case's own history.
			if (trim((string)($row['case'] ?? '')) === $caseId) {
				$rows[] = $row;
			}
		}

		usort(
			$rows,
			static fn (array $a, array $b): int => strcmp(
				(string)($a['eventDate'] ?? ''),
				(string)($b['eventDate'] ?? '')
			)
		);

		return $rows;
	}//end onCase()

	/**
	 * Hand one incident to somebody, without touching the case.
	 *
	 * @param string $incidentId The incident.
	 * @param string $assignee   Who is to work it.
	 *
	 * @return array<string, mixed> The stored incident, or [] when it could not be written.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	public function handOver(string $incidentId, string $assignee): array {
		$context = $this->context();
		if ($context === null || trim($incidentId) === '') {
			return [];
		}

		try {
			$incident = $this->arrayOf(
				row: $context['objects']->find($incidentId, $context['register'], self::SCHEMA)
			);
		} catch (Throwable) {
			return [];
		}

		if ($incident === []) {
			return [];
		}

		$incident['assignee'] = trim($assignee);

		try {
			return $this->arrayOf(
				row: $context['objects']->saveObject(
					object: $incident,
					register: $context['register'],
					schema: self::SCHEMA,
					uuid: $incidentId,
				)
			);
		} catch (Throwable) {
			return [];
		}
	}//end handOver()

	/**
	 * How many incidents on a case still need somebody.
	 *
	 * @param string $caseId The case.
	 *
	 * @return int The count.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	public function openCountOn(string $caseId): int {
		$open = array_filter(
			$this->onCase(caseId: $caseId),
			static fn (array $row): bool => in_array(
				(string)($row['state'] ?? 'open'),
				self::OPEN_STATES,
				true
			)
		);

		return count($open);
	}//end openCountOn()

	/**
	 * How late one incident was written down, in whole days.
	 *
	 * @param array<string, mixed> $incident The incident.
	 *
	 * @return int|null The delay, or null when either moment is missing.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	public function recordingDelayOf(array $incident): ?int {
		$event = trim((string)($incident['eventDate'] ?? ''));
		$recorded = trim((string)($incident['recordedAt'] ?? ''));
		if ($event === '' || $recorded === '') {
			return null;
		}

		$eventAt = date_create_immutable($event);
		$recordedAt = date_create_immutable($recorded);
		if ($eventAt === false || $recordedAt === false) {
			return null;
		}

		// Never negative. An incident recorded before it happened is a clock
		// problem, not a negative delay to render on a page.
		if ($recordedAt < $eventAt) {
			return 0;
		}

		// 🔴 CALENDAR DAYS, NOT ELAPSED ONES, and the difference is an hour a
		// year. From 11 March to 1 April is twenty-one days to every reader,
		// but the clocks go forward in between, so the elapsed time is twenty
		// days and twenty-three hours and `->days` truncates it to twenty. A
		// delay that reads one short for six months of the year is the kind of
		// wrongness nobody reports and everybody half-notices, so both moments
		// are taken down to their own midnight first.
		// Each moment is reduced to the CALENDAR DATE a reader would write,
		// in that moment's own zone, and the two dates are then parsed back in
		// one frame. It names no zone and constructs none: both strings land
		// at midnight in whatever zone the instance runs, and one subtraction
		// in a single frame is exact whatever that zone is. Taking them to
		// midnight in their own zones instead leaves the offset in the
		// subtraction and the missing hour comes straight back.
		$eventDay = date_create_immutable($eventAt->format('Y-m-d'));
		$recordedDay = date_create_immutable($recordedAt->format('Y-m-d'));
		if ($eventDay === false || $recordedDay === false) {
			return null;
		}

		return (int)$eventDay->diff($recordedDay)->days;
	}//end recordingDelayOf()

	/**
	 * The object service and the register, when both resolve.
	 *
	 * @return array{objects: object, register: string}|null The context, or null.
	 */
	private function context(): ?array {
		$objects = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue('register');
		if ($objects === null || $register === '') {
			return null;
		}

		return ['objects' => $objects, 'register' => $register];
	}//end context()

	/**
	 * The rows out of whatever shape the store answered with.
	 *
	 * @param mixed $result The store's answer.
	 *
	 * @return array<int, mixed> The rows.
	 */
	private function rowsOf(mixed $result): array {
		if (is_array($result) === false) {
			return [];
		}

		if (isset($result['results']) === true && is_array($result['results']) === true) {
			return array_values($result['results']);
		}

		return array_values($result);
	}//end rowsOf()

	/**
	 * One row as an array, whatever the store handed back.
	 *
	 * @param mixed $row The row.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function arrayOf(mixed $row): array {
		if (is_array($row) === true) {
			return $row;
		}

		if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
			$serialised = $row->jsonSerialize();
			if (is_array($serialised) === true) {
				return $serialised;
			}
		}

		return [];
	}//end arrayOf()
}//end class
