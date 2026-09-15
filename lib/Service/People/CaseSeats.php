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
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads and writes the handler and the coordinator on a case.
 *
 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
 */
class CaseSeats {

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
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the configured schemas.
	 * @param LoggerInterface $logger          Says why a seat could not be read or written.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
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
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
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
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
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
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function nameCoordinator(string $caseId, string $participant, string $displayName = ''): string {
		$roleTypes = $this->coordinatorRoleTypes();
		if ($roleTypes === []) {
			throw new RuntimeException('no_coordinator_role_type');
		}

		$existing = $this->coordinatorBinding(caseId: $caseId);

		// The uid is the FALLBACK label, never the stored identity: the seat is
		// addressed by `participant`, and a caller that passed no display name
		// gets a row a person can still read rather than an empty one.
		$label = trim($displayName);
		if ($label === '') {
			$label = $this->uidOf(participant: $participant);
		}

		$record = [
			'name' => $label,
			'roleType' => ($existing['roleType'] ?? $roleTypes[0]),
			'case' => $caseId,
			'participant' => $this->referenceOf(participant: $participant),
		];

		$uuid = trim((string)($existing['id'] ?? ''));
		[$objectService, $register] = $this->context();
		$schema = $this->schema(key: 'role_schema');

		if ($uuid === '') {
			$saved = $objectService->saveObject(object: $record, register: $register, schema: $schema);
		} else {
			$saved = $objectService->saveObject(object: $record, register: $register, schema: $schema, uuid: $uuid);
		}

		return $this->idOf(row: $this->asArray(saved: $saved));
	}//end nameCoordinator()

	/**
	 * Empty the coordinator seat, and say who held it.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return string The uid that came off the seat, '' when it was already empty.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
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
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
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

		$cases = [];
		$rows = $this->rows(
			schemaKey: 'role_schema',
			filters: ['participant' => $this->referenceOf(participant: $uid), '_limit' => self::PAGE_SIZE],
		);
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
	}//end casesCoordinatedBy()

	/**
	 * The role types this instance declares as the coordinator seat.
	 *
	 * @return array<int, string> Their uuids.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
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
