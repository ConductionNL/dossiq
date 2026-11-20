<?php

/**
 * Dossiq SearchesConfiguredSchema trait
 *
 * The rows of one configured schema matching a filter, or null when
 * OpenRegister, the register or the schema is missing or the search fails.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared private step; each using class carries its own spec
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * A configured schema's rows through `$this->settingsService` and `searchObjectsAsArrays()`.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait SearchesConfiguredSchema {
	/**
	 * The rows of the schema under the key that match the filters, null when unreadable.
	 *
	 * @param string               $schemaKey The configuration key of the schema.
	 * @param array<string, mixed> $filters   Object-field filters and pagination keys.
	 *
	 * @return array<int, array<string, mixed>>|null The rows, or null when unconfigured or failing.
	 */
	private function configuredRows(string $schemaKey, array $filters): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue($schemaKey);
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: $filters
			);
		} catch (\Throwable $e) {
			return null;
		}
	}//end configuredRows()
}//end trait
