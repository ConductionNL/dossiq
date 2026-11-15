<?php

/**
 * Contract of TemplateLibraryService
 *
 * The public surface of TemplateLibraryService that other components call, so they depend
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
 * Contract of TemplateLibraryService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TemplateLibraryServiceInterface {

	/**
	 * Activate a template by creating OpenRegister objects for the case type and related entities.
	 *
	 * This creates:
	 * - A caseType object
	 * - statusType objects linked to the caseType
	 * - propertyDefinition objects linked to the caseType
	 * - documentType objects linked to the caseType
	 * - decisionType objects linked to the caseType
	 * - roleType objects linked to the caseType
	 * - a workflowTemplate, when the template declares one, with its transitions
	 *   resolved from status names to the ids just created
	 *
	 * @param string $templateId The template identifier
	 *
	 * @return array<string, mixed> Result with created object IDs
	 *
	 * @throws \RuntimeException If template not found or OpenRegister unavailable
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function activateTemplate(string $templateId): array;

	/**
	 * List all available zaaktype templates.
	 *
	 * Scans the templates directory for JSON files and returns their metadata.
	 *
	 * @return array<int, array<string, mixed>> List of template metadata
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function listTemplates(): array;

	/**
	 * Load a template by its ID.
	 *
	 * @param string $templateId The template identifier
	 *
	 * @return array<string, mixed>|null The full template data or null if not found
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function loadTemplate(string $templateId): ?array;
}//end interface
