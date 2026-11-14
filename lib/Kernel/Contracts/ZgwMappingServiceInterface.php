<?php

/**
 * Contract of ZgwMappingService
 *
 * The public surface of ZgwMappingService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of ZgwMappingService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ZgwMappingServiceInterface {

	/**
	 * Delete the mapping configuration for a specific ZGW resource.
	 *
	 * @param string $resourceKey The ZGW resource key (e.g., 'zaak', 'zaaktype')
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function deleteMapping(string $resourceKey): void;

	/**
	 * Get the mapping configuration for a specific ZGW resource.
	 *
	 * @param string $resourceKey The ZGW resource key (e.g., 'zaak', 'zaaktype')
	 *
	 * @return array|null The mapping configuration or null if not found
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getMapping(string $resourceKey): ?array;

	/**
	 * Check whether a mapping exists for a given resource.
	 *
	 * @param string $resourceKey The ZGW resource key
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function hasMapping(string $resourceKey): bool;

	/**
	 * List all ZGW mapping configurations.
	 *
	 * Returns an associative array keyed by resource key. Resources without
	 * a saved configuration will have null values.
	 *
	 * @return array<string, array|null>
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function listMappings(): array;

	/**
	 * Reset a mapping to its default configuration.
	 *
	 * Loads the default from the defaults array and saves it.
	 *
	 * @param string $resourceKey The ZGW resource key
	 * @param array $defaults The default mapping configurations
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function resetToDefault(string $resourceKey, array $defaults): void;

	/**
	 * Save the mapping configuration for a specific ZGW resource.
	 *
	 * @param string $resourceKey The ZGW resource key (e.g., 'zaak', 'zaaktype')
	 * @param array $config The mapping configuration
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function saveMapping(string $resourceKey, array $config): void;
}//end interface
