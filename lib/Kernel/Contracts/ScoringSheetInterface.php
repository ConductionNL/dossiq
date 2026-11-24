<?php

/**
 * Contract of ScoringSheet
 *
 * The public surface of ScoringSheet that other components call, so they depend
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
 * Contract of ScoringSheet.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ScoringSheetInterface {

	/**
	 * The scoring sheet of a case: the formula its submissions are scored by,
	 * the values the case holds for it, the values an evaluator enters per
	 * submission, and the submissions that can be scored, each with the total
	 * the caller gave it so far.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $userId The evaluator's user id.
	 *
	 * @return array<string, mixed> The sheet; `refusal` names why nothing can be scored.
	 */
	public function forCase(string $caseId, string $userId): array;

	/**
	 * Score one submission of the case with the case's formula and store the
	 * caller's evaluation of it, created or updated.
	 *
	 * @param string               $caseId       The case uuid.
	 * @param string               $submissionId The submission (bid) uuid.
	 * @param array<string, mixed> $scores       The evaluator's value per input of the formula.
	 * @param string               $userId       The evaluator's user id.
	 *
	 * @return array<string, mixed> `{evaluationId, total}`, or `{refusal}` when it cannot be scored.
	 */
	public function score(string $caseId, string $submissionId, array $scores, string $userId): array;
}//end interface
