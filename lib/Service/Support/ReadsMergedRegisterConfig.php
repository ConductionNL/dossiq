<?php

/**
 * Dossiq ReadsMergedRegisterConfig trait
 *
 * The shipped register JSON with its register.d fragments merged in, read
 * through the using class's register fragment merger.
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
 * The merged register JSON through `$this->fragments`.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait ReadsMergedRegisterConfig {
	/**
	 * The shipped register JSON with its fragments merged in.
	 *
	 * @return array<string, mixed>|null Null when the file is missing or not a JSON object.
	 */
	private function mergedRegisterConfig(): array|null {
		$configPath = __DIR__ . '/../../Settings/dossiq_register.json';
		if (file_exists($configPath) === false) {
			return null;
		}

		$configData = json_decode((string)file_get_contents($configPath), true);
		if (json_last_error() !== JSON_ERROR_NONE || is_array($configData) === false) {
			return null;
		}

		[$configData] = $this->fragments->merge(
			base: $configData,
			fragmentDir: __DIR__ . '/../../Settings/register.d'
		);

		return $configData;
	}//end mergedRegisterConfig()
}//end trait
