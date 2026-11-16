<?php

/**
 * The object service, register and schema a KCC store writes to.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Kcc
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
 * @spec openspec/changes/archive/2026-06-13-kcc-klantcontact-integratie/tasks.md#TASK-KCC-05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Kcc;

use OCP\AppFramework\OCS\OCSBadRequestException;

/**
 * For a KCC service holding its SettingsService as `$this->settingsService`.
 *
 * @spec openspec/changes/archive/2026-06-13-kcc-klantcontact-integratie/tasks.md#TASK-KCC-05
 */
trait ResolvesKccStore {

	/**
	 * Resolve the ObjectService and the register and schema identifiers.
	 *
	 * @param string $schemaKey    The app-config key holding the schema id.
	 * @param string $unconfigured The refusal when the register or schema is unset.
	 *
	 * @return array{0: object, 1: string, 2: string} ObjectService, register, schema.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable or unconfigured.
	 */
	private function kccStore(string $schemaKey, string $unconfigured): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new OCSBadRequestException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($schemaKey);

		if ($register === '' || $schema === '') {
			throw new OCSBadRequestException($unconfigured);
		}

		return [$objectService, $register, $schema];
	}//end kccStore()
}//end trait
