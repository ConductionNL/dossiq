<?php

/**
 * The register reads a case's declarations start from
 *
 * The object service, a configured schema, a reference's uuid and the case
 * type of a case, each with the refusal a caller shows when it cannot be
 * read. The role links and the hearing sessions both read their
 * declarations from a case's type this way.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use RuntimeException;

/**
 * Case and case type reads, built by their owner.
 *
 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
 */
final class CaseRegisterReads {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The object service and the configured schemas.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The object service, or a refusal when OpenRegister is absent.
	 *
	 * @return object The object service.
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function objectService(): object {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		return $objectService;
	}//end objectService()

	/**
	 * The configured schema behind a config key, or a refusal naming the key.
	 *
	 * @param string $key The config key.
	 *
	 * @return string The schema id or slug.
	 *
	 * @throws RuntimeException When the key is not configured.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function schemaKey(string $key): string {
		$schema = $this->settingsService->getConfigValue(key: $key);
		if ($schema === '') {
			throw new RuntimeException("The schema '$key' is not configured.");
		}

		return $schema;
	}//end schemaKey()

	/**
	 * A uuid from a reference value: a string, or an expanded object.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string The uuid, or ''.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
	 */
	public static function uuidOf(mixed $value): string {
		if (is_string($value) === true) {
			return trim($value);
		}

		if (is_array($value) === true) {
			foreach (['id', 'uuid'] as $key) {
				if (is_string($value[$key] ?? null) === true) {
					return trim($value[$key]);
				}
			}
		}

		return '';
	}//end uuidOf()

	/**
	 * The case type of a case, or null when the case names none.
	 *
	 * @param array{0: object, 1: string} $location The object service and the register.
	 * @param string                      $caseId   The case uuid.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @throws RuntimeException When the case or its case type cannot be read.
	 *
	 * @spec openspec/changes/archive/2026-06-13-complaint-management/tasks.md#task-TASK-CM-02
	 */
	public function caseTypeOf(array $location, string $caseId): ?array {
		[$objectService, $register] = $location;
		$case = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $this->schemaKey(key: 'case_schema'),
			id: $caseId
		);
		if ($case === null) {
			throw new RuntimeException('Case not found');
		}

		$caseTypeId = self::uuidOf(value: ($case['caseType'] ?? ''));
		if ($caseTypeId === '') {
			return null;
		}

		$caseType = $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $this->schemaKey(key: 'case_type_schema'),
			id: $caseTypeId
		);
		if ($caseType === null) {
			throw new RuntimeException('Case type not found');
		}

		return $caseType;
	}//end caseTypeOf()
}//end class
