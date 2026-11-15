<?php

/**
 * Contract of MilestoneSchedule
 *
 * The public surface of MilestoneSchedule that other components call, so they depend
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
 * Contract of MilestoneSchedule.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface MilestoneScheduleInterface {

	/**
	 * The identifiers that form a dependency cycle, or an empty list.
	 *
	 * Called when the case type is saved, which is where the person who can
	 * fix it is (D-3). A cycle discovered at runtime is either a loop or a
	 * date that silently never resolves, and both are found by whoever is
	 * reading the case rather than by whoever wrote the definition.
	 *
	 * @param array<int, array<string, mixed>> $definitions The definitions to check.
	 *
	 * @return string[] The identifiers on the cycle, in the order they chain, or [].
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function cycle(array $definitions): array;
}//end interface
