<?php

/**
 * Contract of AdmissibilityJudgement
 *
 * The public surface of AdmissibilityJudgement that other components call, so they depend
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
 * Contract of AdmissibilityJudgement.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdmissibilityJudgementInterface {

	/**
	 * Record the verdict an intake worker reached, and act on it.
	 *
	 * An admissible verdict is recorded and nothing else happens: moving the
	 * case on is the ordinary transition the phase already offers, and doing it
	 * here would be a second mover of the same case.
	 *
	 * @param string $caseId  The case UUID.
	 * @param string $verdict `admissible` or `inadmissible`.
	 * @param string $reason  Why, recorded on the case and carried into the close.
	 * @param string $judgedBy The uid of the intake worker.
	 *
	 * @return array<string, mixed> What was recorded, and what the close did.
	 *
	 * @throws RefusedException When the case type asks for no verdict, the verdict is
	 *                          not one of the two, the reason is empty, or the case
	 *                          type names no result for an inadmissible aanvraag.
	 *
	 * @spec openspec/specs/besluitvorming-leaf/spec.md#requirement-an-inadmissible-verdict-ends-the-case-at-intake-req-dec-02
	 */
	public function judge(string $caseId, string $verdict, string $reason, string $judgedBy): array;
}//end interface
