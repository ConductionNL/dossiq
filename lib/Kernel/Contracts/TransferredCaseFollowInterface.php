<?php

/**
 * Contract of TransferredCaseFollow
 *
 * The public surface of TransferredCaseFollow that other components call, so they depend
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
 * Contract of TransferredCaseFollow.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TransferredCaseFollowInterface {

	/**
	 * Record an act on a case for the organisation that handed it over.
	 *
	 * Nothing is recorded for a case that was never handed to another
	 * organisation, for an act this follow does not name, or when OpenRegister
	 * or the transfer register is not there. A team handover inside this
	 * organisation is not followed: the sending team stays in the same
	 * organisation and reads the case's own history.
	 *
	 * @param string $caseId The case the act was on.
	 * @param string $act    One of {@see self::ACTS}.
	 * @param string $actor  Who did it, or an empty string for the system.
	 *
	 * @return bool Whether the act was recorded.
	 *
	 * @spec openspec/changes/capability-rows-say-the-whole-capability/specs/federated-case-collaboration/spec.md#requirement-a-transfer-hands-the-case-over-and-the-sender-follows-its-activity-req-crs-42
	 */
	public function record(string $caseId, string $act, string $actor = ''): bool;
}//end interface
