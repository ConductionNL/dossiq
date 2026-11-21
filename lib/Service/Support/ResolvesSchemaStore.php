<?php

/**
 * Dossiq ResolvesSchemaStore trait
 *
 * The object service, the configured register and the schema a key names,
 * read through the using class's settings service, or null when one of them
 * is missing. OpenRegister is asked first, so without it no configuration is
 * read.
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
 * A schema's store through `$this->settingsService`.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait ResolvesSchemaStore {
	/**
	 * The object service, register and schema for one schema key, null when any is missing.
	 *
	 * @param string $schemaKey The settings key of the schema.
	 *
	 * @return array{objectService: object, register: string, schema: string}|null The store.
	 */
	private function schemaStore(string $schemaKey): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema   = $this->settingsService->getConfigValue($schemaKey);
		if ($register === '' || $schema === '') {
			return null;
		}

		return ['objectService' => $objectService, 'register' => $register, 'schema' => $schema];
	}//end schemaStore()
}//end trait
