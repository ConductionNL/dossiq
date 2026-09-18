<?php

/**
 * The dated events inside a case.
 *
 * One address generates a report in March, another in June and a third in
 * September. Today that is three cases, or one case with three paragraphs.
 * An incident is a dated thing with its own reporter, its own owner and its own
 * outcome, sitting inside the case that holds the address and the history.
 * Without that record, the pattern that makes the case worth keeping open is
 * prose.
 *
 * 🔴 AN INCIDENT IS NOT A DEELZAAK (D-4). A deelzaak has its own number, its
 * own beslistermijn and its own decision. An incident has none of the three,
 * and giving every report a statutory clock it does not have is how a case with
 * three reports acquires three deadlines nobody owes.
 *
 * 🔴 ORDER IS THE EVENT DATE, NOT THE CREATION MOMENT (D-6). A report recorded
 * three weeks late belongs where it happened. Both moments are stored so the
 * delay is visible rather than hidden by sorting on the one that is easy.
 *
 * 🔴 AN INCIDENT OWNS ITS OWN HAND-OFF (D-5). The owner of an incident and the
 * owner of the case are different roles and often different people: the case
 * sits with the area handler while one report inside it is worked by an
 * inspector. So assigning an incident touches the incident and never the case.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Incidents
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Incidents;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Recording, listing, assigning and settling the incidents on a case.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class IncidentService {

	use SearchesObjects;

	/**
	 * The state an unsettled incident is in, and what the work list counts.
	 *
	 * @var string
	 */
	public const STATE_OPEN = 'open';

	/**
	 * The state of an incident somebody is working.
	 *
	 * @var string
	 */
	public const STATE_WORKING = 'in-behandeling';

	/**
	 * The state of a settled incident.
	 *
	 * @var string
	 */
	public const STATE_SETTLED = 'afgehandeld';

	/**
	 * The states that count as open on a work list.
	 *
	 * `in-behandeling` counts. An incident somebody is working is not a
	 * finished one, and a count that excluded it would tell a coordinator the
	 * work is done while an inspector is out looking at it.
	 *
	 * @var array<int, string>
	 */
	public const OPEN_STATES = [self::STATE_OPEN, self::STATE_WORKING];

	/**
	 * The refusal when the incident cannot be found.
	 *
	 * @var string
	 */
	public const UNKNOWN = 'incident-unknown';

	/**
	 * The refusal when a report arrives with no date or no description.
	 *
	 * @var string
	 */
	public const INCOMPLETE = 'incident-incomplete';

	/**
	 * The refusal when the record cannot be written.
	 *
	 * @var string
	 */
	public const UNWRITABLE = 'incident-unwritable';

	/**
	 * How many incidents one read takes at most.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface $logger          Records every report and every hand-off.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record one report on a case.
	 *
	 * @param string $caseId      The case it happened inside.
	 * @param string $eventDate   When it happened, in ISO 8601.
	 * @param string $description What happened.
	 * @param string $reporter    Who reported it.
	 * @param string $assignee    Who will work it, or an empty string.
	 *
	 * @return array<string, mixed> The stored incident.
	 *
	 * @throws RefusedException When the report is incomplete or cannot be written.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-dated-incidents-with-their-own-owners-req-inc-01
	 */
	public function record(
		string $caseId,
		string $eventDate,
		string $description,
		string $reporter = '',
		string $assignee = '',
	): array {
		$caseId = trim($caseId);
		$description = trim($description);
		$happened = $this->instant(value: $eventDate);

		if ($caseId === '' || $description === '' || $happened === null) {
			throw new RefusedException(
				rule: self::INCOMPLETE,
				sentence: 'A report needs a case, a date and a description, so nothing was recorded.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $this->write(
			incident: [
				'case' => $caseId,
				'eventDate' => $happened->format('c'),
				'recordedAt' => (new DateTimeImmutable())->format('c'),
				'reporter' => trim($reporter),
				'description' => $description,
				'assignee' => trim($assignee),
				'state' => self::STATE_OPEN,
				'outcome' => '',
			],
			uuid: null,
		);
	}//end record()

	/**
	 * The incidents on a case, oldest event first.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>> The incidents.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-dated-incidents-with-their-own-owners-req-inc-01
	 */
	public function on(string $caseId): array {
		$caseId = trim($caseId);
		if ($caseId === '') {
			return [];
		}

		$rows = $this->rows(filters: ['case' => $caseId, '_limit' => self::PAGE_SIZE]);

		usort(
			$rows,
			static function (array $left, array $right): int {
				return (((string)($left['eventDate'] ?? '')) <=> ((string)($right['eventDate'] ?? '')));
			},
		);

		return $rows;
	}//end on()

	/**
	 * How many days late one incident was written down.
	 *
	 * @param array<string, mixed> $incident The incident.
	 *
	 * @return int|null The delay in days, or null when either moment is missing.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-dated-incidents-with-their-own-owners-req-inc-01
	 */
	public function recordingDelayDays(array $incident): ?int {
		$happened = $this->instant(value: (string)($incident['eventDate'] ?? ''));
		$written = $this->instant(value: (string)($incident['recordedAt'] ?? ''));
		if ($happened === null || $written === null) {
			return null;
		}

		// NEVER NEGATIVE. A record written before the event is a clock out of
		// step, not a report from the future, and a negative delay printed
		// beside a report would read as the second thing.
		$days = (int)$happened->diff($written)->format('%a');
		if ($written < $happened) {
			return 0;
		}

		return $days;
	}//end recordingDelayDays()

	/**
	 * Hand one incident to somebody, leaving the case where it is.
	 *
	 * @param string $incidentId The incident.
	 * @param string $assignee   Who takes it.
	 *
	 * @return array<string, mixed> The stored incident.
	 *
	 * @throws RefusedException When the incident is unknown or cannot be written.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-hands-off-without-moving-the-case-req-inc-02
	 */
	public function assign(string $incidentId, string $assignee): array {
		$incident = $this->require(incidentId: $incidentId);
		$incident['assignee'] = trim($assignee);
		if (trim($assignee) !== '' && ($incident['state'] ?? self::STATE_OPEN) === self::STATE_OPEN) {
			$incident['state'] = self::STATE_WORKING;
		}

		$this->logger->info(
			'Dossiq incident: a report changed hands, and the case did not',
			['incident' => $incidentId, 'assignee' => $assignee, 'case' => ($incident['case'] ?? '')],
		);

		return $this->write(incident: $incident, uuid: $this->uuidOf(row: $incident));
	}//end assign()

	/**
	 * Settle one incident with its outcome.
	 *
	 * @param string $incidentId The incident.
	 * @param string $outcome    What came of it.
	 *
	 * @return array<string, mixed> The stored incident.
	 *
	 * @throws RefusedException When the incident is unknown, the outcome is empty, or the write fails.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-dated-incidents-with-their-own-owners-req-inc-01
	 */
	public function settle(string $incidentId, string $outcome): array {
		$outcome = trim($outcome);
		if ($outcome === '') {
			throw new RefusedException(
				rule: self::INCOMPLETE,
				sentence: 'Say what came of the report, so a settled incident says something.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$incident = $this->require(incidentId: $incidentId);
		$incident['state'] = self::STATE_SETTLED;
		$incident['outcome'] = $outcome;
		$incident['settledAt'] = (new DateTimeImmutable())->format('c');

		return $this->write(incident: $incident, uuid: $this->uuidOf(row: $incident));
	}//end settle()

	/**
	 * The open incidents one person is working.
	 *
	 * Read by the work list. The filter is the ASSIGNEE and not the case's
	 * owner, which is the whole point of REQ-INC-02: an inspector sees the one
	 * report they were handed without the case it sits in moving to them.
	 *
	 * @param string $userId The person.
	 *
	 * @return array<int, array<string, mixed>> The incidents, oldest event first.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-hands-off-without-moving-the-case-req-inc-02
	 */
	public function ownedBy(string $userId): array {
		$userId = trim($userId);
		if ($userId === '') {
			return [];
		}

		$mine = [];
		foreach ($this->rows(filters: ['assignee' => $userId, '_limit' => self::PAGE_SIZE]) as $row) {
			if (in_array((string)($row['state'] ?? ''), self::OPEN_STATES, true) === true) {
				$mine[] = $row;
			}
		}

		usort(
			$mine,
			static function (array $left, array $right): int {
				return (((string)($left['eventDate'] ?? '')) <=> ((string)($right['eventDate'] ?? '')));
			},
		);

		return $mine;
	}//end ownedBy()

	/**
	 * How many open incidents each of these cases holds.
	 *
	 * Returns a count PER CASE rather than a total, because the work list has
	 * to answer both "which cases have open reports" and "how many are there",
	 * and a total cannot be taken apart again.
	 *
	 * @param array<int, string> $caseIds The cases.
	 *
	 * @return array<string, int> The counts, keyed by case, with the zero cases left out.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-hands-off-without-moving-the-case-req-inc-02
	 */
	public function openCountsFor(array $caseIds): array {
		$wanted = [];
		foreach ($caseIds as $caseId) {
			$caseId = trim((string)$caseId);
			if ($caseId !== '') {
				$wanted[] = $caseId;
			}
		}

		if ($wanted === []) {
			return [];
		}

		$counts = [];
		foreach ($this->rows(filters: ['_limit' => self::PAGE_SIZE]) as $row) {
			$caseId = trim((string)($row['case'] ?? ''));
			if ($caseId === '' || in_array($caseId, $wanted, true) === false) {
				continue;
			}

			if (in_array((string)($row['state'] ?? ''), self::OPEN_STATES, true) === false) {
				continue;
			}

			$counts[$caseId] = (($counts[$caseId] ?? 0) + 1);
		}

		return $counts;
	}//end openCountsFor()

	/**
	 * One stored incident, or a refusal.
	 *
	 * @param string $incidentId The incident uuid.
	 *
	 * @return array<string, mixed> The incident.
	 *
	 * @throws RefusedException When it cannot be read.
	 */
	private function require(string $incidentId): array {
		try {
			[$objectService, $register] = $this->context();
			$incident = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'incident_schema'),
				id: trim($incidentId),
			);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: self::UNKNOWN,
				sentence: 'We could not read that report, so nothing was changed.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		if ($incident === null) {
			throw new RefusedException(
				rule: self::UNKNOWN,
				sentence: 'That report could not be found.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $incident;
	}//end require()

	/**
	 * Read incident rows under a filter.
	 *
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(array $filters): array {
		try {
			[$objectService, $register] = $this->context();

			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'incident_schema'),
				filters: $filters,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq incident: the reports could not be read',
				['exception' => $e->getMessage()],
			);

			return [];
		}
	}//end rows()

	/**
	 * Store one incident, new or existing.
	 *
	 * @param array<string, mixed> $incident The incident.
	 * @param string|null          $uuid     The uuid to update, or null to create.
	 *
	 * @return array<string, mixed> The stored incident.
	 *
	 * @throws RefusedException When it could not be stored.
	 */
	private function write(array $incident, ?string $uuid): array {
		unset($incident['@self'], $incident['id'], $incident['uuid']);

		try {
			[$objectService, $register] = $this->context();
			$saved = $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'incident_schema'),
				object: $incident,
				uuid: $uuid,
			);
		} catch (Throwable $e) {
			throw new RefusedException(
				rule: self::UNWRITABLE,
				sentence: 'The report could not be recorded.',
				status: RefusedException::STATUS_INDETERMINATE,
				previous: $e,
			);
		}

		if ($saved === null) {
			throw new RefusedException(
				rule: self::UNWRITABLE,
				sentence: 'The report could not be recorded.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		return $saved;
	}//end write()

	/**
	 * The uuid of a stored row, however the register spelled it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or an empty string.
	 */
	private function uuidOf(array $row): string {
		return trim((string)($row['id'] ?? ($row['uuid'] ?? '')));
	}//end uuidOf()

	/**
	 * A moment, or null when the value is empty or unreadable.
	 *
	 * @param string $value The candidate.
	 *
	 * @return DateTimeImmutable|null The moment.
	 */
	private function instant(string $value): ?DateTimeImmutable {
		$value = trim($value);
		if ($value === '') {
			return null;
		}

		try {
			return new DateTimeImmutable($value);
		} catch (Throwable $e) {
			return null;
		}
	}//end instant()

	/**
	 * The object service and the register, or an exception.
	 *
	 * @return array{0: object, 1: string} The service and the register.
	 *
	 * @throws RuntimeException When OpenRegister is absent or unconfigured.
	 */
	private function context(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			throw new RuntimeException('Dossier register not configured');
		}

		return [$objectService, $register];
	}//end context()

	/**
	 * A configured schema, or an exception naming the key.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The schema id or slug.
	 *
	 * @throws RuntimeException When the key is unset.
	 */
	private function schema(string $key): string {
		$schema = $this->settingsService->getConfigValue($key);
		if ($schema === '') {
			throw new RuntimeException('Dossiq schema ' . $key . ' not configured');
		}

		return $schema;
	}//end schema()
}//end class
