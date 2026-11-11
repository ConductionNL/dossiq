<?php

/**
 * The rows of a configured schema, or none when it cannot be read
 *
 * The reports read their registers tolerantly: a missing OpenRegister or an
 * unconfigured register or schema yields an empty list rather than an error,
 * so a partially configured instance still renders an empty dashboard. The
 * turnaround and process-mining reports both read their schemas this way.
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
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCA\Dossiq\Service\SettingsService;

/**
 * Searches one configured schema of the dossier register, or answers an empty list.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
final class ConfiguredSchemaRows {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService OpenRegister access and the configured register and schemas.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The rows of the schema a configuration key names, under the given filters.
	 *
	 * @param string               $schemaKey The configuration key of the schema.
	 * @param array<string, mixed> $filters   The search filters.
	 *
	 * @return array<int, array<string, mixed>> The rows, or an empty list when
	 *                                          OpenRegister, the register or the schema is missing.
	 *
	 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
	 */
	public function search(string $schemaKey, array $filters): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($schemaKey);
		if (empty($register) === true || empty($schema) === true) {
			return [];
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: $filters,
		);
	}//end search()
}//end class
