<?php

/**
 * Dossiq ReadsConfiguredSource trait
 *
 * The object service, register and one configured schema, read together
 * through the using class's settings service, or null when one is missing.
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
 * The object service, register and a schema through `$this->settingsService`.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait ReadsConfiguredSource {
	/**
	 * The object service, register and the schema under the key, when all are configured.
	 *
	 * @param string $schemaKey The configuration key of the schema.
	 *
	 * @return array{0: object, 1: string, 2: string}|null Null when one is missing.
	 */
	private function configuredSource(string $schemaKey): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($schemaKey);
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		return [$objectService, $register, $schema];
	}//end configuredSource()
}//end trait
