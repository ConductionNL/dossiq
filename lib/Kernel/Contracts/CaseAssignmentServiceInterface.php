<?php

/**
 * Contract of CaseAssignmentService
 *
 * The public surface of CaseAssignmentService that other components call, so they depend
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
 * Contract of CaseAssignmentService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseAssignmentServiceInterface {

	/**
	 * Take this case.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $userId The signed-in user, who becomes the assignee.
	 *
	 * @return array{caseId: string, assignee: string, mine: bool, claimable: bool, releasable: bool} The new assignment.
	 *
	 * @throws RuntimeException With code `case_not_found`, `already_yours` or `already_assigned`.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function claim(string $caseId, string $userId): array;

	/**
	 * Give this case back to the queue.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $userId The signed-in user, who must be the assignee.
	 *
	 * @return array{caseId: string, assignee: string, mine: bool, claimable: bool, releasable: bool} The new assignment.
	 *
	 * @throws RuntimeException With code `case_not_found`, `not_assigned` or `not_yours`.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function release(string $caseId, string $userId): array;

	/**
	 * Who holds this case, and what this user may do about it.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $userId The signed-in user.
	 *
	 * @return array{caseId: string, assignee: string, mine: bool, claimable: bool, releasable: bool} The assignment.
	 *
	 * @throws RuntimeException With code `case_not_found` when the case does not resolve for this user.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function state(string $caseId, string $userId): array;
}//end interface
