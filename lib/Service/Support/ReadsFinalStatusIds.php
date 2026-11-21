<?php

/**
 * Dossiq ReadsFinalStatusIds trait
 *
 * The ids of the status types marked final, read from the configured status type schema.
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
 * The ids of the status types marked final, read from the configured status type schema.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait ReadsFinalStatusIds {
	/**
	 * Resolve the set of final statusType ids (closed/archived cases).
	 *
	 * @param object $objectService The ObjectService.
	 * @param string $register Register id.
	 *
	 * @return array<int, string>
	 */
	private function finalStatusIds(object $objectService, string $register): array {
		$statusTypeSchema = (string)$this->settingsService->getConfigValue('status_type_schema');
		if ($statusTypeSchema === '') {
			return [];
		}

		try {
			$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $statusTypeSchema);
		} catch (\Throwable $e) {
			return [];
		}

		$ids = [];
		foreach ($rows as $row) {
			$isFinal = ($row['isFinal'] ?? false);
			if (in_array($isFinal, [true, 'true', 1], true) === true) {
				$ids[] = (string)($row['id'] ?? ($row['uuid'] ?? ''));
			}
		}

		return array_values(array_filter($ids));
	}//end finalStatusIds()
}//end trait
