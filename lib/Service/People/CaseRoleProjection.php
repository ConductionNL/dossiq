<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * A person linked to a case, projected onto the case's `role` records.
 *
 * The link is the fact a handler creates; the `role` record is what the
 * resolver, the mandate matrix and the Parties widget read, so this keeps
 * one record per link and touches no record it did not write. A link in
 * the generic initiator role also names the requester on the case.
 *
 * @spec openspec/specs/people-on-the-case/spec.md
 */
class CaseRoleProjection {

	use SearchesObjects;

	/**
	 * The generic role that makes a link the case's initiator.
	 */
	public const INITIATOR = 'initiator';

	/**
	 * @param SettingsService $settingsService OpenRegister access and the configured register and schemas.
	 * @param PersonLinkReader $people Reads a link's name and address.
	 * @param LoggerInterface $logger Says what a link could not become.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly PersonLinkReader $people,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Keep the role record of one link: create it, or bring it up to date.
	 *
	 * @param array<string, mixed> $link The link as OpenRegister stores it.
	 *
	 * @return string The role record's uuid, '' when the link produced none.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-001-a-person-linked-to-a-case-shall-become-a-role-record-on-that-case
	 */
	public function project(array $link): string {
		$caseId = trim((string)($link['objectUuid'] ?? ''));
		$personUid = trim((string)($link['contactUid'] ?? ''));
		$roleTypeId = trim((string)($link['role'] ?? ''));
		if ($caseId === '' || $personUid === '') {
			return '';
		}

		$case = $this->findRow(schemaKey: 'case_schema', id: $caseId);
		if ($case === null) {
			// Not a case: people live on every kind of object, and only a
			// case has role records.
			return '';
		}

		$roleType = $this->findRow(schemaKey: 'role_type_schema', id: $roleTypeId);
		if ($roleType === null) {
			$this->logger->info(
				'Dossiq people: a link names no role type of this instance, so no role record was written',
				['case' => $caseId, 'person' => $personUid, 'role' => $roleTypeId]
			);
			return '';
		}

		$record = $this->existingRole(caseId: $caseId, personUid: $personUid, roleTypeId: $roleTypeId);
		$record['case'] = $caseId;
		$record['roleType'] = $roleTypeId;
		$record['participant'] = $personUid;
		$record['name'] = $this->people->nameOf(link: $link);
		$record['description'] = trim((string)($link['note'] ?? ''));

		$uuid = $this->saveRole(record: $record);
		$this->nameInitiator(case: $case, link: $link, roleType: $roleType);

		return $uuid;
	}//end project()

	/**
	 * Remove the role record of a link that is gone.
	 *
	 * @param array<string, mixed> $link The link as it was.
	 *
	 * @return bool True when a record was removed.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-001-a-person-linked-to-a-case-shall-become-a-role-record-on-that-case
	 */
	public function retire(array $link): bool {
		$caseId = trim((string)($link['objectUuid'] ?? ''));
		$personUid = trim((string)($link['contactUid'] ?? ''));
		if ($caseId === '' || $personUid === '') {
			return false;
		}

		$record = $this->existingRole(caseId: $caseId, personUid: $personUid, roleTypeId: trim((string)($link['role'] ?? '')));
		$uuid = trim((string)($record['id'] ?? ''));
		$case = $this->findRow(schemaKey: 'case_schema', id: $caseId);
		if ($case !== null) {
			$this->clearInitiator(case: $case, personUid: $personUid);
		}

		if ($uuid === '') {
			return false;
		}

		[$objectService, $register] = $this->requireRegister();
		$objectService->deleteObject(uuid: $uuid, register: $register, schema: $this->schema(key: 'role_schema'));

		return true;
	}//end retire()

	/**
	 * The role record this projection already wrote for a link, or a fresh one.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $personUid The person's uid.
	 * @param string $roleTypeId The role type uuid.
	 *
	 * @return array<string, mixed> The record, [] when there is none yet.
	 */
	private function existingRole(string $caseId, string $personUid, string $roleTypeId): array {
		$filters = ['case' => $caseId, 'participant' => $personUid, '_limit' => 50];
		if ($roleTypeId !== '') {
			$filters['roleType'] = $roleTypeId;
		}

		try {
			[$objectService, $register] = $this->requireRegister();
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: 'role_schema'),
				filters: $filters,
			);
		} catch (Throwable) {
			return [];
		}

		foreach ($rows as $row) {
			$self = (array)($row['@self'] ?? []);
			$id = trim((string)($row['id'] ?? ($self['id'] ?? '')));
			if ($id !== '') {
				$row['id'] = $id;
				unset($row['@self']);
				return $row;
			}
		}

		return [];
	}//end existingRole()

	/**
	 * Store a role record, new or existing.
	 *
	 * @param array<string, mixed> $record The record.
	 *
	 * @return string The uuid, '' when it could not be read back.
	 */
	private function saveRole(array $record): string {
		[$objectService, $register] = $this->requireRegister();
		$schema = $this->schema(key: 'role_schema');
		$uuid = trim((string)($record['id'] ?? ''));
		unset($record['id']);

		if ($uuid === '') {
			return $this->uuidOf(saved: $objectService->saveObject(object: $record, register: $register, schema: $schema));
		}

		return $this->uuidOf(saved: $objectService->saveObject(object: $record, register: $register, schema: $schema, uuid: $uuid));
	}//end saveRole()

	/**
	 * Name the case's initiator from a link in the generic initiator role.
	 *
	 * `requester` and `initiatorType` are left alone on purpose: a user or a
	 * contact is not a row in the requester register, and writing a uid where
	 * a uuid belongs would break the column that reads it.
	 *
	 * @param array<string, mixed> $case The case row.
	 * @param array<string, mixed> $link The link.
	 * @param array<string, mixed> $roleType The role type the link names.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-003-an-initiator-link-shall-name-the-requester-on-the-case
	 */
	private function nameInitiator(array $case, array $link, array $roleType): void {
		if (trim((string)($roleType['genericRole'] ?? '')) !== self::INITIATOR) {
			return;
		}

		$case['initiatorDisplayName'] = $this->people->nameOf(link: $link);
		$case['initiatorSourceId'] = trim((string)($link['contactUid'] ?? ''));
		$this->saveCase(case: $case);
	}//end nameInitiator()

	/**
	 * Clear the initiator name when the link that set it is gone.
	 *
	 * @param array<string, mixed> $case The case row.
	 * @param string $personUid The person whose link went.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-003-an-initiator-link-shall-name-the-requester-on-the-case
	 */
	private function clearInitiator(array $case, string $personUid): void {
		if (trim((string)($case['initiatorSourceId'] ?? '')) !== $personUid) {
			return;
		}

		$case['initiatorDisplayName'] = '';
		$case['initiatorSourceId'] = '';
		$this->saveCase(case: $case);
	}//end clearInitiator()

	/**
	 * Store the case row back.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return void
	 */
	private function saveCase(array $case): void {
		$uuid = trim((string)($case['id'] ?? ''));
		if ($uuid === '') {
			return;
		}

		unset($case['id'], $case['@self']);
		[$objectService, $register] = $this->requireRegister();
		$objectService->saveObject(
			object: $case,
			register: $register,
			schema: $this->schema(key: 'case_schema'),
			uuid: $uuid,
		);
	}//end saveCase()

	/**
	 * One object of a configured schema by uuid, or null.
	 *
	 * @param string $schemaKey The configuration key of the schema.
	 * @param string $id The uuid.
	 *
	 * @return array<string, mixed>|null The row with `id`.
	 */
	private function findRow(string $schemaKey, string $id): ?array {
		if ($id === '') {
			return null;
		}

		try {
			[$objectService, $register] = $this->requireRegister();
			$row = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $this->schema(key: $schemaKey),
				id: $id,
			);
		} catch (Throwable) {
			return null;
		}

		if ($row === null || $row === []) {
			return null;
		}

		$self = (array)($row['@self'] ?? []);
		$row['id'] = trim((string)($row['id'] ?? ($self['id'] ?? $id)));

		return $row;
	}//end findRow()

	/**
	 * A saved object's uuid.
	 *
	 * @param mixed $saved What saveObject returned.
	 *
	 * @return string The uuid.
	 */
	private function uuidOf(mixed $saved): string {
		if (is_object($saved) === true && is_callable([$saved, 'getUuid']) === true) {
			return (string)call_user_func([$saved, 'getUuid']);
		}

		$row = (array)$saved;
		$self = (array)($row['@self'] ?? []);

		return trim((string)($row['id'] ?? ($row['uuid'] ?? ($self['id'] ?? ''))));
	}//end uuidOf()

	/**
	 * The object service and register, or an exception.
	 *
	 * @return array{0: object, 1: string} The object service and the register.
	 *
	 * @throws RuntimeException When OpenRegister or the register is not configured.
	 */
	private function requireRegister(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		if ($register === '') {
			throw new RuntimeException('Dossier register not configured');
		}

		return [$objectService, $register];
	}//end requireRegister()

	/**
	 * A configured schema slug or id, or an exception when unset.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The schema.
	 *
	 * @throws RuntimeException When the key is not configured.
	 */
	private function schema(string $key): string {
		$schema = $this->settingsService->getConfigValue($key);
		if ($schema === '') {
			throw new RuntimeException('Dossiq schema ' . $key . ' not configured');
		}

		return $schema;
	}//end schema()
}//end class
