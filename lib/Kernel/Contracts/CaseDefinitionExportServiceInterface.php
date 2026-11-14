<?php

/**
 * Contract of CaseDefinitionExportService
 *
 * The public surface of CaseDefinitionExportService that other components call, so they depend
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
 * Contract of CaseDefinitionExportService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseDefinitionExportServiceInterface {

	/**
	 * Export a case definition as a ZIP archive.
	 *
	 * @param string $caseTypeId The OpenRegister ID of the case type to export.
	 * @param string[] $components List of components to include (defaults to all).
	 *
	 * @return array{path: string, filename: string} Temporary file path and suggested filename.
	 *
	 * @throws \RuntimeException If export fails.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function exportCaseDefinition(
		string $caseTypeId,
		array $components = [],
	): array;
}//end interface
