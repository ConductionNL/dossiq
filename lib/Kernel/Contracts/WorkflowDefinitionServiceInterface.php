<?php

/**
 * Contract of WorkflowDefinitionService
 *
 * The public surface of WorkflowDefinitionService that other components call, so they depend
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

use OCA\Dossiq\Service\Workflow\WorkflowLifecycleGuard;

/**
 * Contract of WorkflowDefinitionService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WorkflowDefinitionServiceInterface {

	public const STATUS_PUBLISHED = WorkflowLifecycleGuard::STATUS_PUBLISHED;

	/**
	 * Clone an existing published or deprecated definition into a new
	 * draft with version + 1.
	 *
	 * @param string $id The source definition UUID
	 *
	 * @return array<string, mixed>|null New draft definition or null
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function cloneDefinition(string $id): ?array;

	/**
	 * Deprecate a published definition. Refuses (returns null + logs) if
	 * doing so would leave the caseType with no published definition while
	 * open cases remain pinned to it.
	 *
	 * @param string $id The definition UUID to deprecate
	 *
	 * @return array<string, mixed>|null Updated definition or null
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function deprecate(string $id): ?array;

	/**
	 * Resolve the active definition for a caseType, or null when none exists.
	 * Read-only consumer entrypoint used by status-transition-engine and
	 * role-based-step-routing.
	 *
	 * A case type may carry several routes, with one active definition each.
	 * Naming a route answers for that route. Naming none answers with the case
	 * type's DEFAULT route, which is what every caller written before routes
	 * existed means by "the active definition".
	 *
	 * @param string $caseTypeId The caseType UUID
	 * @param string|null $variant The route to answer for, or null for the default route
	 *
	 * @return array<string, mixed>|null The definition or null
	 *
	 * @spec openspec/specs/workflow-variants/spec.md
	 */
	public function getActiveDefinitionFor(string $caseTypeId, ?string $variant = null): ?array;

	/**
	 * Resolve the definition pinned to a case via case.workflowTemplate +
	 * case.workflowVersion. Falls back to the active definition for the
	 * case's caseType when no pin is set.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return array<string, mixed>|null The definition or null
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getDefinitionForCase(string $caseId): ?array;

	/**
	 * List every version of the definition for a given caseType, ordered
	 * by version descending. Used by the admin UI.
	 *
	 * @param string $caseTypeId The caseType UUID
	 *
	 * @return array<int, array<string, mixed>> The versions
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function listVersions(string $caseTypeId): array;

	/**
	 * Publish a draft definition. Atomically:
	 *   - sets target lifecycleStatus=published, isActive=true, isDraft=false
	 *   - moves any previously active version of the same caseType to
	 *     lifecycleStatus=deprecated, isActive=false
	 *   - updates caseType.workflowDefinition to point at the new active id
	 *
	 * Returns the updated definition or null on error (errors logged).
	 *
	 * @param string $id The definition UUID to publish
	 *
	 * @return array<string, mixed>|null Updated definition or null
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function publish(string $id): ?array;
}//end interface
