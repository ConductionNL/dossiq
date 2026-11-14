<?php

/**
 * Dossiq TaskKeyAt.
 *
 * Which task declares a candidate group or an effect, and where in the definition the list sits.
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
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/process-step-configuration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Task;

/**
 * The task's name and the path a refusal of one of its declared lists names.
 *
 * @spec openspec/specs/process-step-configuration/spec.md
 */
final class TaskKeyAt {

	/**
	 * Constructor.
	 *
	 * @param string $task The task's name.
	 * @param string $path The path of the declared list in the definition.
	 */
	public function __construct(
		public readonly string $task,
		public readonly string $path,
	) {
	}//end __construct()
}//end class
