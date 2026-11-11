<?php

/**
 * Dossiq CaseTask
 *
 * The case and the task a held file is published for.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Task
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
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Task;

/**
 * One completed task on one case, as the caller named them.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/task-management/spec.md
 */
final class CaseTask {
	/**
	 * Constructor.
	 *
	 * @param string $caseId The case.
	 * @param string $taskId The completed task.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $taskId,
	) {
	}//end __construct()
}//end class
