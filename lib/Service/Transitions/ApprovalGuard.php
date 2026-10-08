<?php

/**
 * A transition that waits for an approval decidiq is still walking.
 *
 * Guard type: `approvalGate`. Like the status checklist it is not a guard a
 * template declares: the engine appends it to EVERY transition, carrying the
 * transition's id as the act, and the case type decides whether that act is
 * gated at all.
 *
 * 🔴 WHY IT IS A GUARD AND NOT A CHECK IN ONE CALLER. A case moves through two
 * doors: OpenRegister's lifecycle provider, and dossiq's own transition
 * endpoint, which is the one the acts dialog posts to. A gate in only one of
 * them is a gate the other walks around. Both doors already run the engine's
 * guards, so the gate lives there, once, and the refusal reaches both with the
 * approval named in the guard's own sentence.
 *
 * 🔑 THE VERDICT IS {@see ApprovalGate}'S. This class translates it into the
 * engine's vocabulary and decides nothing itself.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

use OCA\Dossiq\Service\Cases\ApprovalGate;

/**
 * Evaluates the approval gate on one transition of one case.
 *
 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
 */
class ApprovalGuard implements GuardEvaluatorInterface {

	/**
	 * Constructor.
	 *
	 * @param ApprovalGate $gate The one reader of decidiq's outcome.
	 */
	public function __construct(
		private readonly ApprovalGate $gate,
	) {
	}//end __construct()

	/**
	 * Pass an ungated or approved act, refuse the rest with the approval named.
	 *
	 * The details carry the rule and the status the verdict reached, so a
	 * surface can tell "still open" from "the approval service could not be
	 * reached" without parsing the sentence. Both refuse (ADR-102).
	 *
	 * @param array<string, mixed> $guardConfig `{type, act}`, appended by the engine.
	 * @param array<string, mixed> $case        The case.
	 * @param string               $userId      Who is moving it.
	 *
	 * @return GuardResult The verdict in the engine's shape.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md#requirement-a-case-is-gated-by-the-approval-outcome-decidiq-walks-req-dec-01
	 */
	public function evaluate(array $guardConfig, array $case, string $userId): GuardResult {
		$verdict = $this->gate->verdictFor(
			case: $case,
			act: (string)($guardConfig['act'] ?? ''),
			userId: $userId,
		);

		if ($verdict['allowed'] === true) {
			return new GuardResult(passed: true);
		}

		return new GuardResult(
			passed: false,
			failureMessage: (string)$verdict['sentence'],
			details: [
				'rule' => (string)$verdict['rule'],
				'status' => (int)$verdict['status'],
				'approval' => (string)$verdict['label'],
				'approvers' => (array)$verdict['approvers'],
			],
		);
	}//end evaluate()
}//end class
