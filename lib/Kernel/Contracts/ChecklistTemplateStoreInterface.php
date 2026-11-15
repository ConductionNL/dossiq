<?php

/**
 * Contract of ChecklistTemplateStore
 *
 * The public surface of ChecklistTemplateStore that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of ChecklistTemplateStore.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ChecklistTemplateStoreInterface {

	/**
	 * Every checklist template, in the settings editor's flat view.
	 *
	 * Templates are `inspectionChecklistTemplate` objects since
	 * inspection-checklists-onto-task 4.1; the editor keeps its flat shape
	 * (`name`, `caseTypeRef`, `active`, `items`) through
	 * {@see InspectionTemplateMapper}.
	 *
	 * @param string|null $caseTypeRef Optional case type uuid to filter on.
	 *
	 * @return array<int, array<string, mixed>> The checklists.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function all(?string $caseTypeRef = null): array;

	/**
	 * Create a checklist template from the editor's flat payload.
	 *
	 * @param array<string, mixed> $data The flat checklist.
	 *
	 * @return array<string, mixed> The created checklist, flat.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function create(array $data): array;

	/**
	 * Delete a checklist template.
	 *
	 * @param string $id The template uuid.
	 *
	 * @return boolean True when deleted.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function delete(string $id): bool;

	/**
	 * Update a checklist template, moving its version up by one.
	 *
	 * @param string               $id   The template uuid.
	 * @param array<string, mixed> $data The flat checklist.
	 *
	 * @return array<string, mixed> The updated checklist, flat.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function update(string $id, array $data): array;
}//end interface
