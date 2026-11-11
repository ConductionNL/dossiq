<?php

/**
 * Dossiq Configured Case Store value object.
 *
 * Where a status move writes the case: the object service and the configured
 * register and case schema, read once, the configuration only when there is
 * storage to configure.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\SettingsService;

/**
 * The object service, register and case schema a case is written through.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
final class ConfiguredCaseStore {
	/**
	 * The object service, or null when storage is unavailable.
	 *
	 * @var object|null
	 */
	public readonly ?object $objectService;

	/**
	 * The configured register, or '' when unset or unread.
	 *
	 * @var string
	 */
	public readonly string $register;

	/**
	 * The configured case schema, or '' when unset or unread.
	 *
	 * @var string
	 */
	public readonly string $schema;

	/**
	 * Read the storage, and its configuration only when there is storage.
	 *
	 * @param SettingsService $settings The settings.
	 */
	public function __construct(SettingsService $settings) {
		$this->objectService = $settings->getObjectService();
		if ($this->objectService === null) {
			$this->register = '';
			$this->schema = '';
			return;
		}

		$this->register = $settings->getConfigValue(key: 'register');
		$this->schema = $settings->getConfigValue(key: 'case_schema');
	}//end __construct()

	/**
	 * Why the case cannot be written here, or null when it can.
	 *
	 * @return string|null The error code.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md
	 */
	public function refusal(): ?string {
		if ($this->objectService === null) {
			return 'storage_unavailable';
		}

		if ($this->register === '' || $this->schema === '') {
			return 'case_schema_not_configured';
		}

		return null;
	}//end refusal()
}//end class
