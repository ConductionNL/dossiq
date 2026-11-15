<?php

/**
 * Dossiq FindsInConfiguredSchema trait
 *
 * One object of a configured schema by id, or null when OpenRegister, the
 * register, the schema or the id is missing.
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
 * One configured object through `$this->settingsService` and `findObjectAsArray()`.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait FindsInConfiguredSchema {
	/**
	 * The object, or null when it does not exist or nothing is configured; other read failures are thrown.
	 *
	 * @param string $schemaKey The configuration key of the schema.
	 * @param string $id        The object's id.
	 *
	 * @return array<string, mixed>|null The object.
	 */
	private function findInConfiguredSchema(string $schemaKey, string $id): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register      = (string) $this->settingsService->getConfigValue('register');
		$schema        = (string) $this->settingsService->getConfigValue($schemaKey);
		if ($objectService === null || $register === '' || $schema === '' || $id === '') {
			return null;
		}

		return $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id);
	}//end findInConfiguredSchema()
}//end trait
