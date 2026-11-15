<?php

/**
 * Contract of CaseNextStatus
 *
 * The public surface of CaseNextStatus that other components call, so they depend
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

use Throwable;

/**
 * Contract of CaseNextStatus.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseNextStatusInterface {

	/**
	 * The case's next status, `{name, number}`, `[]` when it has none, or null
	 * when the user may not read the case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{name?: string, number?: int}|null
	 *
	 * @throws Throwable When the register cannot be read.
	 *
	 * @spec openspec/changes/task-holds-up-next-status/specs/task-page/spec.md#requirement-a-task-says-which-step-its-case-waits-to-move-to
	 */
	public function forCase(string $caseId): ?array;
}//end interface
