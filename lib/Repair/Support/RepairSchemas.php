<?php

/**
 * The configured schema a repair step walks, when OpenRegister and the configuration have one.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Support
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Support;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;

/**
 * Finds a configured schema in the configured register.
 *
 * @spec exclude Shared upgrade plumbing; each repair step that walks a schema names its
 *  own spec.
 */
final class RepairSchemas {
	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService OpenRegister access and the configured register.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
	) {
	}//end __construct()

	/**
	 * The schema configured under a key, or null when OpenRegister, the register or the schema is missing.
	 *
	 * The object service, the register and the schema are each read before any
	 * of them is checked.
	 *
	 * @param string $schemaKey The configuration key of the schema.
	 *
	 * @return RepairSchema|null The schema to walk, or null.
	 *
	 * @spec exclude Shared upgrade plumbing; each repair step that walks a schema names its
	 *  own spec.
	 */
	public function configured(string $schemaKey): ?RepairSchema {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($schemaKey);
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		$target = new RepairSchema(objectService: $objectService, register: $register);
		$target->schema = $schema;

		return $target;
	}//end configured()
}//end class
