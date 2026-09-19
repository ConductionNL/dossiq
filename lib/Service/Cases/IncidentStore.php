<?php

/**
 * Writing and reading the incidents {@see IncidentRecord} shapes.
 *
 * 🔴 EVERY DECISION IS THE RECORD'S; THIS CLASS ONLY PERSISTS. The order, the
 * recording delay, the open count and the change a hand-off writes all come
 * from `IncidentRecord`, which shipped pure and with no caller: nothing wrote
 * an incident, nothing read one, and the capability was dark. A second opinion
 * here about what "open" means or how a delay is counted would eventually
 * disagree with the first, and the first is the one the tests watch.
 *
 * 🔴 A HAND-OFF TOUCHES THE INCIDENT AND NOTHING ELSE. There is no case writer
 * in this class at all, which is not an oversight but the guarantee: the case
 * sits with the area handler while one report inside it is worked by an
 * inspector, and making that false would mean adding a method rather than
 * changing a condition.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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

namespace OCA\Dossiq\Service\Cases;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Record, list, hand on and settle the reports inside a case.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class IncidentStore {

	use SearchesObjects;

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
	 * @param IncidentRecord  $record          Orders, counts and shapes a hand-off.
	 * @param LoggerInterface $logger          Records every report and every hand-off.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IncidentRecord $record,
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
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
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
				'state' => 'open',
				'outcome' => '',
			],
			uuid: null,
		);
	}//end record()

	/**
	 * The incidents on a case, in the order the record puts them.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>> The incidents.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	public function onCase(string $caseId): array {
		$caseId = trim($caseId);
		if ($caseId === '') {
			return [];
		}

		return $this->record->inEventOrder(
			incidents: $this->rows(filters: ['case' => $caseId, '_limit' => self::PAGE_SIZE]),
		);
	}//end onCase()

	/**
	 * The open incidents one person is working.
	 *
	 * The filter is the ASSIGNEE and not the case's owner, which is the whole
	 * point of the requirement: an inspector sees the one report they were
	 * handed without the case it sits in moving to them.
	 *
	 * @param string $userId The person.
	 *
	 * @return array<int, array<string, mixed>> The incidents, in event order.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-carries-its-own-hand-off-req-cm-54
	 */
	public function ownedBy(string $userId): array {
		$userId = trim($userId);
		if ($userId === '') {
			return [];
		}

		$mine = [];
		foreach ($this->rows(filters: ['assignee' => $userId, '_limit' => self::PAGE_SIZE]) as $row) {
			// ONE ROW AT A TIME THROUGH THE RECORD'S OWN COUNT, so "open" means
			// here exactly what it means on a work list. An absent state counts
			// as open there, and a filter written here would have to remember
			// that separately.
			if ($this->record->openCount(incidents: [$row]) === 1) {
				$mine[] = $row;
			}
		}

		return $this->record->inEventOrder(incidents: $mine);
	}//end ownedBy()

	/**
	 * How many open incidents each of these cases holds.
	 *
	 * Per case rather than a total, because a work list has to answer both
	 * "which cases have open reports" and "how many are there", and a total
	 * cannot be taken apart again. A case with none is left OUT rather than
	 * given a zero: a zero in the answer puts an idle case on a work list.
	 *
	 * @param array<int, string> $caseIds The cases.
	 *
	 * @return array<string, int> The counts, keyed by case.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-carries-its-own-hand-off-req-cm-54
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

		$byCase = [];
		foreach ($this->rows(filters: ['_limit' => self::PAGE_SIZE]) as $row) {
			$caseId = trim((string)($row['case'] ?? ''));
			if ($caseId !== '' && in_array($caseId, $wanted, true) === true) {
				$byCase[$caseId][] = $row;
			}
		}

		$counts = [];
		foreach ($byCase as $caseId => $incidents) {
			$open = $this->record->openCount(incidents: $incidents);
			if ($open > 0) {
				$counts[$caseId] = $open;
			}
		}

		return $counts;
	}//end openCountsFor()

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
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-an-incident-carries-its-own-hand-off-req-cm-54
	 */
	public function assign(string $incidentId, string $assignee): array {
		$incident = $this->require(incidentId: $incidentId);

		$this->logger->info(
			'Dossiq incident: a report changed hands, and the case did not',
			['incident' => $incidentId, 'assignee' => $assignee, 'case' => ($incident['case'] ?? '')],
		);

		return $this->write(
			incident: array_merge(
				$incident,
				$this->record->handoverChange(incident: $incident, assignee: $assignee),
			),
			uuid: $this->uuidOf(row: $incident),
		);
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
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
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
		$incident['state'] = 'afgehandeld';
		$incident['outcome'] = $outcome;

		return $this->write(incident: $incident, uuid: $this->uuidOf(row: $incident));
	}//end settle()

	/**
	 * How many days late one report was written down.
	 *
	 * A pass-through, so a caller holding this store does not also have to hold
	 * the record to print the delay beside a row.
	 *
	 * @param array<string, mixed> $incident The incident.
	 *
	 * @return int|null The delay in days, or null when either moment is missing.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-holds-several-dated-incidents-req-cm-53
	 */
	public function recordingDelayDays(array $incident): ?int {
		return $this->record->recordingDelayDays(incident: $incident);
	}//end recordingDelayDays()

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
				schema: $this->schema(key: 'case_incident_schema'),
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
				schema: $this->schema(key: 'case_incident_schema'),
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
				schema: $this->schema(key: 'case_incident_schema'),
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
