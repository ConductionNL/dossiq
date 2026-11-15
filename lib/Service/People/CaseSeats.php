<?php

/**
 * The two named seats on a case: the handler, and the coordinator.
 *
 * OTOBO carries `ticket.user_id` beside `ticket.responsible_user_id`, and
 * xxllnc calls the pair behandelaar and casemanager. dossiq's handler stays
 * `assignee`, because My Work, the queue and every lens already read it and a
 * rename would break the fleet for nothing.
 *
 * 🔑 THE COORDINATOR IS A ROLE BINDING, NOT A SECOND COLUMN (D-4). A `role`
 * record whose role type carries the generic role `coordinator` is the seat.
 * That keeps the party model one mechanism: the People tab, the resolver and
 * the mandate matrix all read role records already, and a case type can add a
 * third seat later without a schema change. A second `coordinator` column on
 * the case would have been a second place to look and a second thing to keep
 * in step.
 *
 * WHY THE ROLE TYPE IS LOOKED UP AND NEVER HARDCODED. `genericRole` is the
 * ZGW `omschrijvingGeneriek`, and an instance names its own role types on top
 * of it: Casemanager, Zaakregisseur, Coordinator. There is one generic value
 * and many names, so the lookup is by the generic value and the name is
 * whatever the instance chose.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\People
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/people-on-the-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use OCA\Dossiq\Kernel\Contracts\CaseSeatsInterface;
use OCA\Dossiq\Service\Custody\DossierRegister;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads and writes the handler and the coordinator on a case.
 *
 * @spec openspec/specs/people-on-the-case/spec.md
 */
class CaseSeats implements CaseSeatsInterface {

	use SearchesObjects;

	/**
	 * The seat that does the work. It is `assignee` on the case and stays so.
	 *
	 * @var string
	 */
	public const HANDLER = 'handler';

	/**
	 * The seat that is answerable for the work.
	 *
	 * @var string
	 */
	public const COORDINATOR = 'coordinator';

	/**
	 * The ZGW generic role a coordinator role type carries.
	 *
	 * @var string
	 */
	public const GENERIC_COORDINATOR = 'coordinator';

	/**
	 * The prefix a Nextcloud user's participant reference carries (REQ-POC-001).
	 *
	 * @var string
	 */
	public const USER_PREFIX = 'user:';

	/**
	 * How many rows one seat lookup reads.
	 *
	 * @var int
	 */
	public const PAGE_SIZE = 200;

	/**
	 * The register and the configured schemas, read and refused the one shared way.
	 *
	 * @var DossierRegister
	 */
	private readonly DossierRegister $dossierRegister;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface $logger          Says why a seat could not be read or written.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md
	 */
	public function __construct(
		SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
		$this->dossierRegister = new DossierRegister(settingsService: $settingsService);
	}//end __construct()

	/**
	 * Both seats on one case.
	 *
	 * @param array<string, mixed> $case The stored case.
	 *
	 * @return array{handler: string, coordinator: string, coordinatorRole: string}
	 *         The handler uid, the coordinator uid, and the uuid of the role
	 *         record holding the coordinator seat ('' when the seat is empty).
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function seatsOf(array $case): array {
		$caseId = $this->idOf(row: $case);
		$binding = $this->coordinatorBinding(caseId: $caseId);

		return [
			'handler' => trim((string)($case['assignee'] ?? '')),
			'coordinator' => $this->uidOf(participant: (string)($binding['participant'] ?? '')),
			'coordinatorRole' => trim((string)($binding['id'] ?? '')),
		];
	}//end seatsOf()

	/**
	 * The role record holding the coordinator seat on a case, or an empty array.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed> The role record, or [].
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function coordinatorBinding(string $caseId): array {
		if ($caseId === '') {
			return [];
		}

		$roleTypes = $this->coordinatorRoleTypes();
		if ($roleTypes === []) {
			return [];
		}

		foreach ($this->rows(schemaKey: 'role_schema', filters: ['case' => $caseId, '_limit' => self::PAGE_SIZE]) as $row) {
			if (in_array(trim((string)($row['roleType'] ?? '')), $roleTypes, true) === true) {
				return $row;
			}
		}

		return [];
	}//end coordinatorBinding()

	/**
	 * Name the coordinator on a case, replacing whoever held the seat.
	 *
	 * @param string $caseId      The case uuid.
	 * @param string $participant The person, as a uid or a `user:<uid>` reference.
	 * @param string $displayName What to call them on the People tab.
	 *
	 * @return string The role record's uuid.
	 *
	 * @throws RuntimeException When the instance declares no coordinator role type.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function nameCoordinator(string $caseId, string $participant, string $displayName = ''): string {
		$roleTypes = $this->coordinatorRoleTypes();
		if ($roleTypes === []) {
			throw new RuntimeException('no_coordinator_role_type');
		}

		$existing = $this->coordinatorBinding(caseId: $caseId);

		$record = $this->coordinatorRecord(
			caseId: $caseId,
			participant: $participant,
			displayName: $displayName,
			roleType: ($existing['roleType'] ?? $roleTypes[0])
		);

		return $this->writeCoordinator(record: $record, uuid: trim((string)($existing['id'] ?? '')));
	}//end nameCoordinator()

	/**
	 * The coordinator role record to write.
	 *
	 * @param string $caseId      The case uuid.
	 * @param string $participant The person, as a uid or a `user:<uid>` reference.
	 * @param string $displayName What to call them on the People tab.
	 * @param mixed  $roleType    The role type: the existing row's, or the first declared.
	 *
	 * @return array<string, mixed> The record.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	private function coordinatorRecord(string $caseId, string $participant, string $displayName, mixed $roleType): array {
		// The uid is the FALLBACK label, never the stored identity: the seat is
		// addressed by `participant`, and a caller that passed no display name
		// gets a row a person can still read rather than an empty one.
		$label = trim($displayName);
		if ($label === '') {
			$label = $this->uidOf(participant: $participant);
		}

		return [
			'name' => $label,
			'roleType' => $roleType,
			'case' => $caseId,
			'participant' => $this->referenceOf(participant: $participant),
		];
	}//end coordinatorRecord()

	/**
	 * Write the coordinator record: a new row, or a patch of the existing one.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string               $uuid   The existing row's uuid, '' when there is none.
	 *
	 * @return string The role record's uuid.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	private function writeCoordinator(array $record, string $uuid): string {
		[$objectService, $register] = $this->context();
		$schema = $this->schema(key: 'role_schema');

		if ($uuid === '') {
			return $this->idOf(row: $this->asArray(saved: $objectService->saveObject(object: $record, register: $register, schema: $schema)));
		}

		// 🔴 A PATCH, NOT A SAVE. Handing OpenRegister a partial object WITH a
		// uuid replaces the stored one, and this record carries four fields
		// this method never sets: `description`, `delegate`, `delegateFrom`
		// and `delegateUntil`. A save would have silently ended a running
		// delegation every time somebody renamed the coordinator.
		return $this->idOf(
			row: (array)$this->patchObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $uuid, changes: $record)
		);
	}//end writeCoordinator()

	/**
	 * Empty the coordinator seat, and say who held it.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return string The uid that came off the seat, '' when it was already empty.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function clearCoordinator(string $caseId): string {
		$existing = $this->coordinatorBinding(caseId: $caseId);
		$holder = $this->uidOf(participant: (string)($existing['participant'] ?? ''));
		$uuid = trim((string)($existing['id'] ?? ''));
		if ($uuid === '') {
			return '';
		}

		try {
			[$objectService, $register] = $this->context();
			$objectService->deleteObject(
				register: $register,
				schema: $this->schema(key: 'role_schema'),
				uuid: $uuid,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq seats: the coordinator seat could not be emptied',
				['caseId' => $caseId, 'exception' => $e->getMessage()],
			);
			return '';
		}

		return $holder;
	}//end clearCoordinator()

	/**
	 * The cases a person coordinates.
	 *
	 * The counterpart of the `assignee` filter every lens already has. A
	 * coordinator on four cases and handler on none finds nothing through
	 * `assignee`, which is the whole point of the second seat being findable.
	 *
	 * @param string $uid The person's Nextcloud user id.
	 *
	 * @return array<int, string> The case uuids, in the order they were read.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function casesCoordinatedBy(string $uid): array {
		$uid = trim($uid);
		if ($uid === '') {
			return [];
		}

		$roleTypes = $this->coordinatorRoleTypes();
		if ($roleTypes === []) {
			return [];
		}

		$rows = $this->rows(
			schemaKey: 'role_schema',
			filters: ['participant' => $this->referenceOf(participant: $uid), '_limit' => self::PAGE_SIZE],
		);
		return $this->caseIdsIn(rows: $rows, roleTypes: $roleTypes);
	}//end casesCoordinatedBy()

	/**
	 * The distinct case ids of the role rows that carry a coordinator role type.
	 *
	 * @param array<int, array<string, mixed>> $rows      The participant's role rows.
	 * @param array<int, string>               $roleTypes The coordinator role types.
	 *
	 * @return array<int, string>
	 */
	private function caseIdsIn(array $rows, array $roleTypes): array {
		$cases = [];
		foreach ($rows as $row) {
			if (in_array(trim((string)($row['roleType'] ?? '')), $roleTypes, true) === false) {
				continue;
			}

			$caseId = trim((string)($row['case'] ?? ''));
			if ($caseId !== '' && in_array($caseId, $cases, true) === false) {
				$cases[] = $caseId;
			}
		}

		return $cases;
	}//end caseIdsIn()

	/**
	 * The role types this instance declares as the coordinator seat.
	 *
	 * @return array<int, string> Their uuids.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function coordinatorRoleTypes(): array {
		$types = [];
		foreach ($this->rows(schemaKey: 'role_type_schema', filters: ['_limit' => self::PAGE_SIZE]) as $row) {
			if (trim((string)($row['genericRole'] ?? '')) !== self::GENERIC_COORDINATOR) {
				continue;
			}

			$id = $this->idOf(row: $row);
			if ($id !== '') {
				$types[] = $id;
			}
		}

		return $types;
	}//end coordinatorRoleTypes()

	/**
	 * Rows of one configured schema, or an empty list when it cannot be read.
	 *
	 * @param string               $schemaKey The configuration key naming the schema.
	 * @param array<string, mixed> $filters   The search filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(string $schemaKey, array $filters): array {
		try {
			[$objectService, $register] = $this->context();

			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: $schemaKey),
				filters: $filters,
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq seats: a seat lookup could not be answered',
				['schema' => $schemaKey, 'exception' => $e->getMessage()],
			);

			return [];
		}
	}//end rows()

	/**
	 * The object service and register, or an exception.
	 *
	 * @return array{0: object, 1: string} The service and the register.
	 *
	 * @throws RuntimeException When OpenRegister is absent or unconfigured.
	 */
	private function context(): array {
		return $this->dossierRegister->context();
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
		return $this->dossierRegister->schema(key: $key);
	}//end schema()

	/**
	 * The uuid of a row, wherever the reader put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or ''.
	 */
	private function idOf(array $row): string {
		$self = (array)($row['@self'] ?? []);

		return trim((string)($row['id'] ?? ($row['uuid'] ?? ($self['id'] ?? ''))));
	}//end idOf()

	/**
	 * A saved object as a plain array.
	 *
	 * @param mixed $saved What the object service returned.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function asArray(mixed $saved): array {
		if (is_array($saved) === true) {
			return $saved;
		}

		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			return (array)$saved->jsonSerialize();
		}

		return [];
	}//end asArray()

	/**
	 * The bare uid behind a participant reference.
	 *
	 * @param string $participant A uid or a `user:<uid>` reference.
	 *
	 * @return string The uid.
	 */
	private function uidOf(string $participant): string {
		$participant = trim($participant);
		if (str_starts_with($participant, self::USER_PREFIX) === true) {
			return substr($participant, strlen(self::USER_PREFIX));
		}

		return $participant;
	}//end uidOf()

	/**
	 * The participant reference a Nextcloud user is stored under.
	 *
	 * @param string $participant A uid or an already-prefixed reference.
	 *
	 * @return string The reference.
	 */
	private function referenceOf(string $participant): string {
		$participant = trim($participant);
		if ($participant === '' || str_contains($participant, ':') === true) {
			return $participant;
		}

		return self::USER_PREFIX . $participant;
	}//end referenceOf()
}//end class
