<?php

/**
 * What a handler may do to a case right now, and who the case is waiting on.
 *
 * The permission half of the act menu, kept apart from the state half. A
 * verdict, an always-available act and an outstanding approval are all
 * answers to "may I, and is anyone in my way"; whether the case is held,
 * drafted or archived is a different question and lives in
 * {@see CaseActsOverview}.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Lifecycle;

use OCA\Dossiq\Service\CaseType\AlwaysAvailableActs;
use OCA\Dossiq\Service\Cases\ApprovalGate;
use OCP\IUserSession;

/**
 * The permission half of the act menu.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
class CaseActsMenu {

	/**
	 * The three acts whose permission the case type declares.
	 *
	 * @var list<string>
	 */
	private const ROLE_GATED = ['finish', 'abort', 'archive'];

	/**
	 * Constructor.
	 *
	 * @param LifecycleActorGate $gate Whether an act is permitted, and which role is missing.
	 * @param AlwaysAvailableActs|null $alwaysAvailable The acts the case type allows in every
	 *                                                  phase. Optional, so an instance that
	 *                                                  predates the half still answers.
	 * @param IUserSession|null $userSession Names the caller the always-available acts are
	 *                                       decided for.
	 * @param ApprovalGate|null $approvals What the case is waiting for, and on whom. Optional
	 *                                     for the same reason `alwaysAvailable` is.
	 */
	public function __construct(
		private readonly LifecycleActorGate $gate,
		private readonly ?AlwaysAvailableActs $alwaysAvailable = null,
		private readonly ?IUserSession $userSession = null,
		private readonly ?ApprovalGate $approvals = null,
	) {
	}//end __construct()

	/**
	 * The menu for one case: the verdicts, the always-available acts and the
	 * approvals it is waiting on.
	 *
	 * 🔴 A REFUSED ACT IS LISTED, NOT OMITTED. The menu shows it disabled with
	 * the reason, because an act that is simply absent teaches nobody why.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return array<string, mixed> The three menu keys.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function forCase(string $caseId, array $case): array {
		return [
			'acts' => $this->verdicts(case: $case),
			// The second half of "what may I do right now": the acts the case
			// type allows in EVERY phase, beside the acts of the current one.
			// One answer and one menu, because two lists are two places to
			// look and one of them gets forgotten.
			//
			// 🔑 NOT A PHASE THAT IS ALWAYS ACTIVE. Modelled as a phase, an
			// always-available act would appear in the phase strip, count
			// towards the progress figure and be given a phase term, and all
			// three would be wrong. It is its own declared list, marked, and
			// it never reaches the status machinery.
			'alwaysAvailable' => $this->alwaysAvailableOn(caseId: $caseId),
			// What the case is waiting for, and on whom (REQ-DEC-01). Drawn
			// above the acts rather than only on the act that is blocked: a
			// handler who opens the case wants to know who to chase before
			// they go looking for the button that will refuse them.
			//
			// 🔑 THE NAMES ARE DECIDIQ'S. dossiq keeps no approver, so an
			// empty list here means decidiq named nobody, and the sentence
			// says that rather than rendering as "waiting on nobody".
			'awaitingApproval' => $this->awaitingApprovalOn(case: $case),
		];
	}//end forCase()

	/**
	 * The three role-gated acts, each with its verdict and its reason.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return array<int, array<string, mixed>> One entry per act.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	private function verdicts(array $case): array {
		$acts = [];
		foreach (self::ROLE_GATED as $act) {
			$allowed = $this->gate->may(act: $act, case: $case);

			// A permitted act carries no reason, so the menu has nothing to
			// render beside it. Only a refusal explains itself.
			$reason = '';
			if ($allowed === false) {
				$reason = $this->gate->refusalSentence(act: $act, case: $case);
			}

			$acts[] = [
				'act' => $act,
				'allowed' => $allowed,
				'role' => $this->gate->roleFor(act: $act, case: $case),
				'reason' => $reason,
			];
		}

		return $acts;
	}//end verdicts()

	/**
	 * The acts this case type allows in every phase, decided for this caller.
	 *
	 * Answers the empty list when the half is not wired, which is what every
	 * caller constructing this class without it gets: the half is additive,
	 * and a caller written before it cannot be failed by it.
	 *
	 * @param string $caseId The case.
	 *
	 * @return array<int, array<string, mixed>> The acts, each carrying its verdict.
	 *
	 * @spec openspec/changes/task-as-a-first-class-record/specs/process-step-configuration/spec.md
	 */
	private function alwaysAvailableOn(string $caseId): array {
		if ($this->alwaysAvailable === null) {
			return [];
		}

		return $this->alwaysAvailable->forCase(
			caseId: $caseId,
			userId: (string)($this->userSession?->getUser()?->getUID() ?? '')
		);
	}//end alwaysAvailableOn()

	/**
	 * What this case is waiting for, and on whom (REQ-DEC-01).
	 *
	 * Answers the empty list when the half is not wired, for the same reason
	 * the always-available acts do.
	 *
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return array<int, array<string, mixed>> The outstanding approvals.
	 *
	 * @spec openspec/changes/decision-outcomes-on-the-case/specs/besluitvorming-leaf/spec.md
	 */
	private function awaitingApprovalOn(array $case): array {
		if ($this->approvals === null) {
			return [];
		}

		return $this->approvals->awaiting(
			case: $case,
			userId: (string)($this->userSession?->getUser()?->getUID() ?? '')
		);
	}//end awaitingApprovalOn()
}//end class
