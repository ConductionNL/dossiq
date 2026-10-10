<?php

/**
 * Dossiq record-import store
 *
 * The reads and writes a case-record import makes against OpenRegister: every
 * row of a source schema page by page, the case type by uuid or identifier,
 * the cases an earlier run wrote keyed by the source uuid they came from, the
 * case and its result, and the stamp on the source read back. Split from
 * CaseRecordImport so the import keeps only the order of the steps.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Import
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Import;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * OpenRegister reads and writes of a case-record import.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
 */
class RecordImportStore {

	use SearchesObjects;

	/**
	 * One page of a listing.
	 */
	private const PAGE = 500;

	/**
	 * Constructor.
	 *
	 * @param SettingsService   $settingsService Register, schemas and OpenRegister.
	 * @param RecordCaseMapping $mapping         Reads a dot path of a case.
	 * @param ITimeFactory      $time            The moment of the stamp.
	 * @param LoggerInterface   $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly RecordCaseMapping $mapping,
		private readonly ITimeFactory $time,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every record of a declared import's source schema, or null when it could not be read.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $import        The declaration.
	 *
	 * @return array<int, array<string, mixed>>|null The records.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
	 */
	public function sources(object $objectService, array $import): ?array {
		try {
			return $this->listAll(
				objectService: $objectService,
				register: (string)($import['sourceRegister'] ?? ''),
				schema: (string)($import['sourceSchema'] ?? ''),
				filters: []
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'RecordImportStore: the source records could not be read',
				['import' => (string)($import['key'] ?? ''), 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end sources()

	/**
	 * Write the case, and the result a closed record ended with.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $mapped        What the mapping answered.
	 *
	 * @return string The case uuid, or '' when it was not written.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
	 */
	public function writeCase(object $objectService, array $mapped): string {
		$register = (string)$this->settingsService->getConfigValue('register');
		try {
			$saved = $objectService->saveObject(
				object: $mapped['case'],
				register: $register,
				schema: $this->settingsService->getConfigValue('case_schema'),
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->error('RecordImportStore: the case could not be written', ['error' => $e->getMessage()]);
			return '';
		}

		$caseId = '';
		if (is_object($saved) === true && method_exists($saved, 'getUuid') === true) {
			$caseId = (string)$saved->getUuid();
		}

		if ($caseId === '') {
			$caseId = $this->idOf(row: ($this->objectToArrayOrNull(value: $saved) ?? []));
		}

		if ($caseId !== '' && $mapped['result'] !== '') {
			$this->writeResult(objectService: $objectService, register: $register, caseId: $caseId, resultType: $mapped['result']);
		}

		return $caseId;
	}//end writeCase()

	/**
	 * The result a closed record ended with. A refusal is logged; the case stands.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $register      The dossiq register.
	 * @param string $caseId        The case.
	 * @param string $resultType    The result type uuid.
	 *
	 * @return void
	 */
	private function writeResult(object $objectService, string $register, string $caseId, string $resultType): void {
		try {
			$objectService->saveObject(
				object: ['case' => $caseId, 'resultType' => $resultType],
				register: $register,
				schema: $this->settingsService->getConfigValue('result_schema'),
				_rbac: false,
				_multitenancy: false,
			);
		} catch (Throwable $e) {
			$this->logger->warning('RecordImportStore: the result of a closed record could not be written', ['case' => $caseId, 'error' => $e->getMessage()]);
		}
	}//end writeResult()

	/**
	 * Stamp the source, and read the stamp back: a dropped key is a refusal.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $import        The declaration.
	 * @param string               $sourceId      The source uuid.
	 * @param string               $caseId        The case it moved to.
	 *
	 * @return bool True when the source kept the stamp.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
	 */
	public function stamp(object $objectService, array $import, string $sourceId, string $caseId): bool {
		$caseField = (string)($import['stamp']['caseId'] ?? '');
		if ($caseField === '') {
			// Without a declared stamp the next run cannot tell a moved record
			// from one that never moved, so nothing counts as moved.
			return false;
		}

		$changes = [$caseField => $caseId];
		$atField = (string)($import['stamp']['at'] ?? '');
		if ($atField !== '') {
			$changes[$atField] = $this->time->getDateTime()->format(DATE_ATOM);
		}

		try {
			$stored = $this->patchObjectAsArray(
				objectService: $objectService,
				register: (string)($import['sourceRegister'] ?? ''),
				schema: (string)($import['sourceSchema'] ?? ''),
				id: $sourceId,
				changes: $changes
			);
		} catch (Throwable $e) {
			$this->logger->error('RecordImportStore: the stamp was refused', ['record' => $sourceId, 'error' => $e->getMessage()]);
			return false;
		}

		return (string)($stored[$caseField] ?? '') === $caseId;
	}//end stamp()

	/**
	 * The cases an earlier run wrote, keyed by the source uuid they came from.
	 *
	 * Filtered in PHP: OpenRegister has no filter on a nested JSON key of a
	 * magic table, so a declared `matchField` such as `a.b` cannot be asked for.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param array<string, mixed> $caseType      The case type.
	 * @param array<string, mixed> $import        The declaration.
	 *
	 * @return array<string, string> Source uuid to case uuid.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
	 */
	public function casesBySource(object $objectService, array $caseType, array $import): array {
		$matchField = (string)($import['matchField'] ?? '');
		if ($matchField === '') {
			return [];
		}

		try {
			$cases = $this->listAll(
				objectService: $objectService,
				register: (string)$this->settingsService->getConfigValue('register'),
				schema: (string)$this->settingsService->getConfigValue('case_schema'),
				filters: ['caseType' => $this->idOf(row: $caseType)]
			);
		} catch (Throwable $e) {
			$this->logger->warning('RecordImportStore: the existing cases could not be read', ['error' => $e->getMessage()]);
			return [];
		}

		$bySource = [];
		foreach ($cases as $case) {
			$sourceId = trim((string)$this->mapping->read(case: $case, path: $matchField));
			if ($sourceId !== '') {
				$bySource[$sourceId] = $this->idOf(row: $case);
			}
		}

		return $bySource;
	}//end casesBySource()

	/**
	 * The case type, by uuid or identifier.
	 *
	 * @param object $objectService The OpenRegister ObjectService.
	 * @param string $caseType      The uuid or identifier.
	 *
	 * @return array<string, mixed>|null The case type.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
	 */
	public function caseType(object $objectService, string $caseType): ?array {
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_type_schema');
		try {
			$found = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseType);
			if ($found !== null) {
				return $found;
			}

			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['identifier' => $caseType, '_limit' => 1]
			);
		} catch (Throwable $e) {
			$this->logger->warning('RecordImportStore: the case type could not be read', ['caseType' => $caseType, 'error' => $e->getMessage()]);
			return null;
		}

		return ($rows[0] ?? null);
	}//end caseType()

	/**
	 * Every row of one schema, page by page.
	 *
	 * @param object               $objectService The OpenRegister ObjectService.
	 * @param string               $register      Register id or slug.
	 * @param string               $schema        Schema id or slug.
	 * @param array<string, mixed> $filters       Equality filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
	 */
	public function listAll(object $objectService, string $register, string $schema, array $filters): array {
		$rows = [];
		$seen = [];
		$offset = 0;
		do {
			$page = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: $filters + ['_limit' => self::PAGE, '_offset' => $offset]
			);
			foreach ($page as $row) {
				$id = $this->idOf(row: $row);
				if ($id !== '' && isset($seen[$id]) === true) {
					// A store that ignores the offset answers the same page again.
					return $rows;
				}

				$seen[$id] = true;
				$rows[] = $row;
			}

			$offset += self::PAGE;
			$full = (count($page) >= self::PAGE);
		} while ($full === true);

		return $rows;
	}//end listAll()

	/**
	 * A row's uuid, wherever the store put it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or ''.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-every-declared-source-record-is-imported-exactly-once-req-cri-002
	 */
	public function idOf(array $row): string {
		return (string)($row['id'] ?? ($row['@self']['id'] ?? ($row['uuid'] ?? '')));
	}//end idOf()
}//end class
