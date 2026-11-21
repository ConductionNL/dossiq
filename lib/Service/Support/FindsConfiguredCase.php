<?php

/**
 * Dossiq FindsConfiguredCase trait
 *
 * The stored case read fresh from the configured register and case schema.
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
 * The stored case read fresh from the configured register and case schema.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait FindsConfiguredCase {
	/**
	 * Read the case fresh.
	 *
	 * @param string $caseId The case id.
	 *
	 * @return array<string, mixed>|null The case, or null when it does not exist or nothing is configured.
	 */
	private function find(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register      = (string) $this->settingsService->getConfigValue('register');
		$schema        = (string) $this->settingsService->getConfigValue('case_schema');
		if ($objectService === null || $register === '' || $schema === '' || $caseId === '') {
			return null;
		}

		return $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseId);
	}//end find()
}//end trait
