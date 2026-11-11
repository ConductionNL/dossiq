<?php

/**
 * Dossiq TaskFormAt
 *
 * Which task declares a form, and where in the definition it sits.
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
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Task;

/**
 * The task's name and the path a refusal of its form names.
 *
 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
 */
final class TaskFormAt {
	/**
	 * Constructor.
	 *
	 * @param string $task The task's name.
	 * @param string $path The path of its form in the definition.
	 */
	public function __construct(
		public readonly string $task,
		public readonly string $path,
	) {
	}//end __construct()
}//end class
