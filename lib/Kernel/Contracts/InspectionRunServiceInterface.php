<?php

/**
 * Contract of InspectionRunService
 *
 * The public surface of InspectionRunService that other components call, so they depend
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
 * Contract of InspectionRunService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface InspectionRunServiceInterface {

	/**
	 * The task kind every run carries.
	 *
	 * @var string
	 */
	public const KIND = 'inspection';

	/**
	 * OpenRegister's task lifecycle, resolved by name.
	 *
	 * @var string
	 */
	public const TASK_SERVICE = 'OCA\\OpenRegister\\Service\\Task\\TaskService';

	/**
	 * Every run on a case, newest last.
	 *
	 * Read as the acting user with the inbox's all-scope, the same read
	 * {@see \OCA\Dossiq\Service\Task\EngineTaskInbox::forCase()} makes: the
	 * caller has passed dossiq's case read check before this runs.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $actor  The reading uid.
	 *
	 * @return array<int, array<string, mixed>> The runs.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-results-are-stored-under-the-schemas-own-property-names
	 */
	public function runsForCase(string $caseId, string $actor): array;

	/**
	 * Submit one run: check it, create its task, complete it.
	 *
	 * @param string               $caseId     The case uuid.
	 * @param string               $templateId The template uuid, or the uuid of the older checklist it was folded from.
	 * @param array<string, mixed> $payload    The request body.
	 * @param string               $actor      The inspector's uid.
	 *
	 * @return array<string, mixed> The run, as {@see runsForCase()} answers one.
	 *
	 * @throws RuntimeException When the template is unknown, the run breaks a rule, or the task layer is absent.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-rapport-creation
	 */
	public function submit(string $caseId, string $templateId, array $payload, string $actor): array;
}//end interface
