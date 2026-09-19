<?php

/**
 * Dossiq case merge store.
 *
 * Where a merge reads and writes: the configured register, the `case` schema
 * and the schema behind any slug the merge rule names, the case itself, the
 * rows that hang off it, and the uuid behind a reference OpenRegister may hand
 * back either bare or extended.
 *
 * Split out of {@see \OCA\Dossiq\Service\CaseMergeService}, which was over its
 * complexity ceiling. What is left there is what a merge DECIDES and DOES;
 * what moved here is where it looks and what it writes. Every method that used
 * to swallow a Throwable and log it still does, on the same logger, so a
 * failed read is still a null and a failed write is still a false.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Where a case merge reads and writes.
 *
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */
class CaseMergeStore {

	use SearchesObjects;

	/**
	 * The register every dossiq schema lives in.
	 */
	private const REGISTER_CONFIG_KEY = 'register';

	/**
	 * The `case` schema's config key.
	 */
	private const CASE_SCHEMA_CONFIG_KEY = 'case_schema';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register and schema ids, and the object service.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Read a case as an array.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null The case, or null when it cannot be read.
	 */
	public function readCase(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->registerId();
		$schema = $this->caseSchemaId();
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: case "' . $caseId . '" could not be read: ' . $e->getMessage());
			return null;
		}
	}//end readCase()

	/**
	 * Write a few fields onto a case.
	 *
	 * @param string               $caseId  The case uuid.
	 * @param array<string, mixed> $changes The fields to write.
	 *
	 * @return bool True when the write landed.
	 */
	public function patchCase(string $caseId, array $changes): bool {
		return $this->patchRow(schemaId: $this->caseSchemaId(), id: $caseId, changes: $changes);
	}//end patchCase()

	/**
	 * Write a few fields onto one row of any dossiq schema.
	 *
	 * @param string               $schemaId The schema id.
	 * @param string               $id       The row uuid.
	 * @param array<string, mixed> $changes  The fields to write.
	 *
	 * @return bool True when the write landed.
	 */
	public function patchRow(string $schemaId, string $id, array $changes): bool {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->registerId();
		if ($objectService === null || $register === '' || $schemaId === '') {
			return false;
		}

		try {
			return $this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schemaId,
				id: $id,
				changes: $changes
			) !== null;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: a merge write on "' . $id . '" failed: ' . $e->getMessage()
			);
			return false;
		}
	}//end patchRow()

	/**
	 * Every row of a schema that points at one case.
	 *
	 * @param string               $schemaId The schema id.
	 * @param array<string, mixed> $filters  The field filter.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function rowsFor(string $schemaId, array $filters): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->registerId();
		if ($objectService === null || $register === '') {
			return [];
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schemaId,
				filters: $filters
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: a merge read failed: ' . $e->getMessage());
			return [];
		}
	}//end rowsFor()

	/**
	 * The configured schema id behind a schema slug.
	 *
	 * @param string $slug The schema slug as the register declares it.
	 *
	 * @return string The id, empty when the slug is unknown or unconfigured.
	 */
	public function schemaId(string $slug): string {
		$configKey = (string)(SchemaSlugMap::SLUG_TO_CONFIG_KEY[$slug] ?? '');
		if ($configKey === '') {
			return '';
		}

		return (string)$this->settingsService->getConfigValue($configKey);
	}//end schemaId()

	/**
	 * The uuid behind a reference, which OpenRegister hands back either as the
	 * bare id or as the extended object it points at.
	 *
	 * @param mixed $value The stored reference.
	 *
	 * @return string The uuid, empty when there is none.
	 */
	public function referencedId(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_array($value) === true) {
			return trim((string)($value['id'] ?? ''));
		}

		return '';
	}//end referencedId()

	/**
	 * The configured register id.
	 *
	 * @return string The id, empty when unconfigured.
	 */
	private function registerId(): string {
		return (string)$this->settingsService->getConfigValue(self::REGISTER_CONFIG_KEY);
	}//end registerId()

	/**
	 * The configured `case` schema id.
	 *
	 * @return string The id, empty when unconfigured.
	 */
	private function caseSchemaId(): string {
		return (string)$this->settingsService->getConfigValue(self::CASE_SCHEMA_CONFIG_KEY);
	}//end caseSchemaId()

}//end class
