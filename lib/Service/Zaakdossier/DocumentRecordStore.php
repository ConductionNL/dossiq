<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;
use Throwable;

/**
 * The OpenRegister side of the document projection: cases, records, joins
 * and document types, read and written as plain rows.
 *
 * Kept apart from DocumentProjectionService so the rules there read as
 * rules and can be tested against this class doubled, while this class is
 * tested once against a doubled object service. Every row handed out
 * carries `id`; every row taken in is stored without its `@self` block.
 *
 * @spec openspec/specs/document-projection/spec.md
 */
class DocumentRecordStore {
	use SearchesObjects;

	/**
	 * @param SettingsService $settingsService OpenRegister access and the configured register and schemas.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * One case by uuid, or null when the uuid is not a case.
	 *
	 * @param string $caseId The uuid.
	 *
	 * @return array<string, mixed>|null The case row.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function findCase(string $caseId): ?array {
		try {
			[$objectService, $register] = $this->requireRegister();
			$row = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $this->schema(key: 'case_schema'), id: $caseId);
		} catch (Throwable) {
			return null;
		}

		if ($row === null || $row === []) {
			return null;
		}

		return $this->rowOf(value: $row);
	}//end findCase()

	/**
	 * One document record, by uuid or by the file id it names.
	 *
	 * @param string $recordId The uuid, or '' to look up by file id.
	 * @param int $fileId The Nextcloud file id, when no uuid is given.
	 *
	 * @return array<string, mixed>|null The record row with `id`, null when there is none.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function findRecord(string $recordId = '', int $fileId = 0): ?array {
		[$objectService, $register] = $this->requireRegister();
		$infoSchema = $this->schema(key: 'dossier_informatieobject_schema');

		if ($recordId !== '') {
			try {
				$row = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $infoSchema, id: $recordId);
			} catch (Throwable) {
				return null;
			}

			if ($row === null || $row === []) {
				return null;
			}

			return $this->rowOf(value: $row);
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $infoSchema,
			filters: ['fileId' => $fileId, '_limit' => 1],
		);
		if ($rows === []) {
			return null;
		}

		return $this->rowOf(value: $rows[0]);
	}//end findRecord()

	/**
	 * Store a record, new or existing.
	 *
	 * @param array<string, mixed> $record The record row; `id` names an existing one.
	 *
	 * @return string The record's uuid.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function saveRecord(array $record): string {
		[$objectService, $register] = $this->requireRegister();
		$uuid = $this->idOf(row: $record);
		$schema = $this->schema(key: 'dossier_informatieobject_schema');
		$object = $this->withoutSelf(row: $record);
		if ($uuid === '') {
			return $this->resolveSavedUuid(saved: $objectService->saveObject(object: $object, register: $register, schema: $schema));
		}

		$saved = $objectService->saveObject(object: $object, register: $register, schema: $schema, uuid: $uuid);

		return $this->resolveSavedUuid(saved: $saved);
	}//end saveRecord()

	/**
	 * Delete a record.
	 *
	 * @param string $recordId The uuid.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function deleteRecord(string $recordId): void {
		[$objectService, $register] = $this->requireRegister();
		$objectService->deleteObject(uuid: $recordId, register: $register, schema: $this->schema(key: 'dossier_informatieobject_schema'));
	}//end deleteRecord()

	/**
	 * The joins of a record, all of them or those of one case.
	 *
	 * @param string $recordId The record uuid.
	 * @param string $caseId A case uuid, or '' for every case.
	 *
	 * @return array<int, array<string, mixed>> The join rows.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function joinsFor(string $recordId, string $caseId = ''): array {
		[$objectService, $register] = $this->requireRegister();
		$filters = ['informatieobject' => $recordId, '_limit' => 200];
		if ($caseId !== '') {
			$filters['case'] = $caseId;
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $this->schema(key: 'dossier_zaakinformatieobject_schema'),
			filters: $filters,
		);

		return array_map(fn (array $row): array => $this->rowOf(value: $row), $rows);
	}//end joinsFor()

	/**
	 * Join a case and a record, unless they are joined already.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $recordId The record uuid.
	 *
	 * @return bool True when a join was created.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function ensureJoin(string $caseId, string $recordId): bool {
		if ($this->joinsFor(recordId: $recordId, caseId: $caseId) !== []) {
			return false;
		}

		[$objectService, $register] = $this->requireRegister();
		$objectService->saveObject(
			object: [
				'case' => $caseId,
				'informatieobject' => $recordId,
				'natureRelationshipDisplay' => 'Hoort bij, omgekeerd: kent',
				'registrationDate' => date('Y-m-d\TH:i:s\Z'),
			],
			register: $register,
			schema: $this->schema(key: 'dossier_zaakinformatieobject_schema'),
		);

		return true;
	}//end ensureJoin()

	/**
	 * Delete a record's joins, all of them or those of one case.
	 *
	 * @param string $recordId The record uuid.
	 * @param string $caseId A case uuid, or '' for every case.
	 *
	 * @return int How many joins went.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function deleteJoins(string $recordId, string $caseId = ''): int {
		[$objectService, $register] = $this->requireRegister();
		$joinSchema = $this->schema(key: 'dossier_zaakinformatieobject_schema');
		$deleted = 0;
		foreach ($this->joinsFor(recordId: $recordId, caseId: $caseId) as $join) {
			$joinId = $this->idOf(row: $join);
			if ($joinId === '') {
				continue;
			}

			$objectService->deleteObject(uuid: $joinId, register: $register, schema: $joinSchema);
			$deleted++;
		}

		return $deleted;
	}//end deleteJoins()

	/**
	 * A document type by uuid, or the register's first by title when the uuid is empty or unknown.
	 *
	 * @param string $typeId A type uuid, or ''.
	 *
	 * @return array<string, mixed> The type row, [] when the register has none.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function findDocumentType(string $typeId): array {
		[$objectService, $register] = $this->requireRegister();
		$typeSchema = $this->schema(key: 'dossier_informatieobjecttype_schema');

		if ($typeId !== '') {
			try {
				$row = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $typeSchema, id: $typeId);
				if ($row !== null && $row !== []) {
					return $this->rowOf(value: $row);
				}
			} catch (Throwable) {
				// Fall through to the register's first type.
			}
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $typeSchema,
			filters: ['_limit' => 1, '_order' => ['title' => 'ASC']],
		);
		if ($rows === []) {
			return [];
		}

		return $this->rowOf(value: $rows[0]);
	}//end findDocumentType()

	/**
	 * Store a file in an object's folder, attached to that object.
	 *
	 * @param string $objectId The object uuid, a case.
	 * @param string $fileName The file name.
	 * @param string $content The bytes.
	 *
	 * @return int The Nextcloud file id, 0 when it could not be read back.
	 *
	 * @throws RuntimeException When OpenRegister's file service is unavailable or the write fails.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function storeFileOnObject(string $objectId, string $fileName, string $content): int {
		$fileService = $this->settingsService->getFileService();
		if ($fileService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		try {
			$file = $fileService->addFile(
				objectEntity: $objectId,
				fileName: $fileName,
				content: $content,
				share: false,
				tags: [],
				registerId: $this->settingsService->getConfigValue('register'),
			);
		} catch (Throwable $e) {
			throw new RuntimeException('The file could not be stored on ' . $objectId . ': ' . $e->getMessage(), 0, $e);
		}

		if (is_object($file) === false || is_callable([$file, 'getFileId']) === false) {
			return 0;
		}

		return (int)call_user_func([$file, 'getFileId']);
	}//end storeFileOnObject()

	/**
	 * A row's uuid, from `id`, `uuid` or `@self.id`.
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
			throw new RuntimeException('Dossier schema ' . $key . ' not configured');
		}

		return $schema;
	}//end schema()

	/**
	 * A saved object's uuid.
	 *
	 * @param mixed $saved What saveObject returned.
	 *
	 * @return string The uuid.
	 */
	private function resolveSavedUuid(mixed $saved): string {
		if (is_object($saved) === true && is_callable([$saved, 'getUuid']) === true) {
			return (string)call_user_func([$saved, 'getUuid']);
		}

		return $this->idOf(row: (array)$saved);
	}//end resolveSavedUuid()

	/**
	 * An object as an array with `id`, whatever the object service handed back.
	 *
	 * @param mixed $value An entity, an array or null.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function rowOf(mixed $value): array {
		$row = [];
		if (is_object($value) === true && is_callable([$value, 'jsonSerialize']) === true) {
			$row = (array)$value->jsonSerialize();
			if (isset($row['id']) === false && is_callable([$value, 'getUuid']) === true) {
				$row['id'] = (string)call_user_func([$value, 'getUuid']);
			}
		}

		if (is_array($value) === true) {
			$row = $value;
		}

		if ($row !== [] && isset($row['id']) === false) {
			$row['id'] = $this->idOf(row: $row);
		}

		return $row;
	}//end rowOf()

	/**
	 * A row without its metadata block, as saveObject wants it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return array<string, mixed> The row without `@self`.
	 */
	private function withoutSelf(array $row): array {
		unset($row['@self']);
		return $row;
	}//end withoutSelf()
}//end class
