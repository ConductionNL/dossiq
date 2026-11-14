<?php

/**
 * Contract of OpenRegisterGrantsGateway
 *
 * The public surface of OpenRegisterGrantsGateway that other components call, so they depend
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
 * Contract of OpenRegisterGrantsGateway.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OpenRegisterGrantsGatewayInterface {

	/**
	 * Whether OpenRegister refuses this caller the write a move needs.
	 *
	 * The one question a caller actually has, answered in the one class that
	 * may ask it. It lives here rather than in the caller for two reasons. The
	 * interpretation of OpenRegister's record belongs beside the code that
	 * fetched it, so there is one place to read when the record's shape
	 * changes. And a caller that had to assemble the answer from
	 * `provenanceForCase()` and `refuses()` itself would be doing the reading
	 * OpenRegister already did, one step closer to the second evaluator D-1
	 * forbids.
	 *
	 * 🔑 IT ANSWERS `true` ONLY WHEN OPENREGISTER ITSELF SAID NO. Every other
	 * outcome, including "OpenRegister could not be asked", is `false`.
	 * Reading an absence as a refusal would lock every handler out of every
	 * case on an instance that has not upgraded to openregister#3726, which is
	 * a worse failure than the one this change is about. The authorization
	 * that still runs in that case is OpenRegister's own, at the object API,
	 * which dossiq never bypasses.
	 *
	 * The record is logged verbatim rather than summarised, so the refusal is
	 * traceable to the rule instead of to an empty screen (D-5).
	 *
	 * @param string      $caseId The case uuid.
	 * @param string|null $userId The caller, null when the session decides.
	 *
	 * @return bool True only when OpenRegister refused the write.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function refusesTheWrite(string $caseId, ?string $userId = null): bool;
}//end interface
