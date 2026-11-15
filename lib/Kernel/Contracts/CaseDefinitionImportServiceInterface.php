<?php

/**
 * Contract of CaseDefinitionImportService
 *
 * The public surface of CaseDefinitionImportService that other components call, so they depend
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
 * Contract of CaseDefinitionImportService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseDefinitionImportServiceInterface {

	/**
	 * Import a case definition package.
	 *
	 * @param string $zipPath Path to the uploaded ZIP file.
	 * @param string $strategy Conflict resolution strategy: 'skip', 'overwrite', or 'merge'.
	 *
	 * @return array{success: bool, message: string, components: array<string, array{status: string, message: string}>}
	 *
	 * @throws \RuntimeException If import fails.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function importCaseDefinition(
		string $zipPath,
		string $strategy = 'skip',
	): array;

	/**
	 * Validate a case definition package without importing it.
	 *
	 * Kept here so a caller that has the import service does not also need the
	 * validator: the reading itself lives in {@see PackageValidator}.
	 *
	 * @param string $zipPath Path to the uploaded ZIP file.
	 *
	 * @return array{valid: bool, errors: string[], warnings: string[], manifest: ?array<string, mixed>, conflicts: array<string, mixed>}
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function validatePackage(string $zipPath): array;
}//end interface
