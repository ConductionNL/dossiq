<?php

/**
 * Contract of ApprovalRequest
 *
 * The public surface of ApprovalRequest that other components call, so they depend
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

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of ApprovalRequest.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ApprovalRequestInterface {

	/**
	 * Ask decidiq for the approval one act of one case waits on.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $act    The gated act.
	 * @param string $userId Who is asking.
	 *
	 * @return array{act: string, decisionRef: string, raisedAt: string} The link that was recorded.
	 *
	 * @throws RefusedException When the case is gone, the act is not gated, or decidiq cannot be reached.
	 *
	 * @spec openspec/specs/besluitvorming-leaf/spec.md#requirement-a-case-is-gated-by-the-approval-outcome-decidiq-walks-req-dec-01
	 */
	public function raise(string $caseId, string $act, string $userId): array;
}//end interface
