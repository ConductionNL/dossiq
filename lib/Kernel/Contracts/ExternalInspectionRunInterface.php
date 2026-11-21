<?php

/**
 * Contract of ExternalInspectionRunService
 *
 * The public surface of ExternalInspectionRunService that other components call, so they depend
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
 * Contract of ExternalInspectionRunService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ExternalInspectionRunInterface {

	/**
	 * The portal action that completes an external inspection task, named in
	 * the task's `metadata.completeVia`.
	 *
	 * @var string
	 */
	public const ACTION = 'completeChecklistTask';

	/**
	 * Give an external inspector a checklist to run on a case.
	 *
	 * @param string               $caseId       The case uuid.
	 * @param string               $templateId   The template uuid, or the uuid of the older checklist it was folded from.
	 * @param string               $inspectorRef The external inspector's portal subject reference.
	 * @param array<string, mixed> $payload      The request body (`inspection`, `dueAt`).
	 * @param string               $actor        The uid assigning the run.
	 *
	 * @return array<string, mixed> The open run, in the shape the results endpoint answers.
	 *
	 * @throws RuntimeException When the reference is missing, the template is unknown or the task layer is absent.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/design.md
	 */
	public function assign(string $caseId, string $templateId, string $inspectorRef, array $payload, string $actor): array;

	/**
	 * Complete an external inspection task for the portal subject it is assigned to.
	 *
	 * @param array<string, mixed> $claims  The verified assertion claims (`sub`, `audience`, `organisation`, `trust`, `jti`).
	 * @param string               $taskId  The task uuid.
	 * @param array<string, mixed> $payload The answers and remarks.
	 *
	 * @return array{status: int, body: array<string, mixed>} The HTTP status and body.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/design.md
	 */
	public function complete(array $claims, string $taskId, array $payload): array;
}//end interface
