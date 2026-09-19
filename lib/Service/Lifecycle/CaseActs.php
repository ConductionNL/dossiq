<?php

/**
 * Every act on a case, behind one collaborator.
 *
 * The acts are six classes because they are six subjects: ending a case,
 * holding it, drafting it, recording what it is missing, who may do which, and
 * whether its status is the process's. That split is right, and a controller
 * that took all six plus the store plus the gate reached fifteen collaborators
 * and twelve constructor parameters, which phpmd reads as a class doing too
 * many jobs. It was.
 *
 * 🔑 THIS IS A SEAM, NOT A LAYER. It decides nothing and validates nothing:
 * every method here is one line onto the class that owns the act. What it
 * adds is {@see self::overview()}, which is the one thing that genuinely
 * needed all six at once, and which was the reason the controller had them.
 *
 * So the controller now has one collaborator for the acts, and the question
 * "what may this handler do to this case" has a home that is not a
 * controller method.
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

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseType\AlwaysAvailableActs;
use OCA\Dossiq\Service\Cases\ApprovalGate;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCP\IUserSession;

/**
 * One seam over the six classes that own the acts on a case.
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) The ten acts ARE the surface,
 *  and one seam over them is what this class is for. The work behind each one
 *  already lives in its own class: CaseEndingActs, CaseHoldActs, DraftCaseActs,
 *  CaseIncompleteness and CaseArchiveState. Splitting the seam would put the
 *  decomposition back on the caller, who would then have to know which half of
 *  a case's lifecycle an act belongs to before it could ask for it.
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Eleven constructor arguments
 *  because there are eleven act classes to front. Every one is a collaborator
 *  this class delegates to and holds no other state; grouping them would invent
 *  a middle layer whose only job is to be shorter to write down.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
class CaseActs {

	/**
	 * The three acts whose permission the case type declares.
	 *
	 * @var list<string>
	 */
	private const ROLE_GATED = ['finish', 'abort', 'archive'];

	/**
	 * Constructor.
	 *
	 * @param CaseEndingActs $endings Finish, abort and archive.
	 * @param CaseHoldActs $holds Hold and release.
	 * @param DraftCaseActs $drafts Begin and promote.
	 * @param CaseIncompleteness $incompleteness Record what is missing.
	 * @param LifecycleActorGate $gate Whether an act is permitted, and which role is missing.
	 * @param ProcessOwnedStatusRule $processStatus Whether a status may be hand-set.
	 * @param CaseStatusStore $store Reads the case the overview is about.
	 * @param AlwaysAvailableActs|null $alwaysAvailable The acts the case type allows in every
	 *                                                  phase. Optional, so a test that predates
	 *                                                  the half builds the facade as it did.
	 * @param IUserSession|null $userSession Names the caller the always-available acts are
	 *                                       decided for.
	 * @param CaseArchiveState|null $archiveState Reads the platform's archive marker, so the
	 *                                            menu can offer Restore instead of Archive.
	 *                                            Optional for the same reason
	 *                                            `alwaysAvailable` is.
	 * @param ApprovalGate|null $approvals What the case is waiting for, and on whom. Optional
	 *                                     for the same reason the two above it are.
	 */
	public function __construct(
		private readonly CaseEndingActs $endings,
		private readonly CaseHoldActs $holds,
		private readonly DraftCaseActs $drafts,
		private readonly CaseIncompleteness $incompleteness,
		private readonly LifecycleActorGate $gate,
		private readonly ProcessOwnedStatusRule $processStatus,
		private readonly CaseStatusStore $store,
		private readonly ?AlwaysAvailableActs $alwaysAvailable = null,
		private readonly ?IUserSession $userSession = null,
		private readonly ?CaseArchiveState $archiveState = null,
		private readonly ?ApprovalGate $approvals = null,
	) {
	}//end __construct()

	/**
	 * What this case is waiting for, and on whom (REQ-DEC-01).
	 *
	 * Answers the empty list when the half is not wired, for the same reason
	 * the always-available acts do: the half is additive and a test that
	 * constructed this facade before it existed cannot be failed by it.
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

	/**
	 * The acts this case type allows in every phase, decided for this caller.
	 *
	 * Answers the empty list when the half is not wired, which is what every
	 * test constructing this facade seven-argument gets: the half is
	 * additive, and a test written before it cannot be failed by it.
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
	 * What this handler may do to this case, with a reason on every refusal.
	 *
	 * 🔴 A REFUSED ACT IS LISTED, NOT OMITTED. The menu shows it disabled with
	 * the reason, because an act that is simply absent teaches nobody why. So
	 * the answer carries every role-gated act, each with `allowed` and, when it
	 * is not, the sentence naming the role.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The state and the verdicts.
	 *
	 * @throws RefusedException When the case cannot be read.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function overview(string $caseId): array {
		$case = $this->store->loadCase(caseId: $caseId);
		if ($case === null) {
			throw new RefusedException(
				rule: 'case-not-found',
				sentence: 'This case could not be found.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$missing = $this->incompleteness->missingOn(case: $case);

		// The archive marker, read off the case the overview already loaded.
		// The menu needs it to offer Restore in the place Archive stood: two
		// entries both enabled would let a handler archive a case that is
		// already archived, and the platform answers that with a shrug rather
		// than a refusal, so nothing on screen would say what happened.
		$marker = ($this->archiveState?->markerOn(case: $case) ?? []);

		return [
			'caseId' => $caseId,
			'archived' => ($marker !== []),
			'archivedAt' => (string)($marker['at'] ?? ''),
			'archivedBy' => (string)($marker['by'] ?? ''),
			'archivedReason' => (string)($marker['reason'] ?? ''),
			'held' => $this->holds->isHeld(case: $case),
			'heldUntil' => (string)($case[CaseHoldActs::UNTIL_FIELD] ?? ''),
			'draft' => $this->drafts->isDraft(case: $case),
			'incomplete' => ($missing !== []),
			'missingFields' => $missing,
			'endingAct' => (string)($case[CaseEndingActs::ENDING_FIELD] ?? ''),
			'ending' => $this->endings->endingOf(case: $case),
			'statusIsHandSettable' => $this->processStatus->allowsHandSet(
				caseTypeId: (string)($case['caseType'] ?? '')
			),
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
	}//end overview()

	/**
	 * Finish the case: it reached its result.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why, recorded on the case.
	 * @param string $resultTypeId The result type the handler picked.
	 * @param string $toStatus The terminal status to land on, resolved when empty.
	 *
	 * @return array<string, mixed> What was recorded.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function finish(string $caseId, string $reason, string $resultTypeId, string $toStatus): array {
		return $this->endings->finish(
			caseId: $caseId,
			reason: $reason,
			resultTypeId: $resultTypeId,
			toStatus: $toStatus,
		);
	}//end finish()

	/**
	 * Abort the case: an intrekking, and no besluit.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why the case was aborted.
	 * @param string $resultTypeId The result type, e.g. Ingetrokken.
	 * @param string $toStatus The terminal status to land on, resolved when empty.
	 *
	 * @return array<string, mixed> What was recorded.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function abort(string $caseId, string $reason, string $resultTypeId, string $toStatus): array {
		return $this->endings->abort(
			caseId: $caseId,
			reason: $reason,
			resultTypeId: $resultTypeId,
			toStatus: $toStatus,
		);
	}//end abort()

	/**
	 * Archive a finished case: commit the retention its result type carries.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it is being archived.
	 *
	 * @return array<string, mixed> The nomination and action date written.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function archive(string $caseId, string $reason): array {
		return $this->endings->archive(caseId: $caseId, reason: $reason);
	}//end archive()

	/**
	 * Take the case back out of the archive.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it is coming back.
	 *
	 * @return array<string, mixed> The archive status it carries afterwards.
	 *
	 * @spec openspec/changes/archived-cases-leave-the-lenses/specs/case-management/spec.md
	 */
	public function unarchive(string $caseId, string $reason): array {
		return $this->endings->unarchive(caseId: $caseId, reason: $reason);
	}//end unarchive()

	/**
	 * Hold the case until a date.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it is not being worked.
	 * @param string $until The date it returns, as Y-m-d.
	 *
	 * @return array<string, mixed> The hold as it was recorded.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function hold(string $caseId, string $reason, string $until): array {
		return $this->holds->hold(caseId: $caseId, reason: $reason, until: $until);
	}//end hold()

	/**
	 * Take the case off hold before its date.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $reason Why it is being picked up again.
	 *
	 * @return array<string, mixed> The hold state afterwards.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function releaseHold(string $caseId, string $reason): array {
		return $this->holds->release(caseId: $caseId, reason: $reason);
	}//end releaseHold()

	/**
	 * Make this case a draft: no term, no list, its author only.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> How the case now reads.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function draft(string $caseId): array {
		return $this->drafts->begin(caseId: $caseId);
	}//end draft()

	/**
	 * Promote a draft into a case, binding the term.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> The bound term and the kept draft moment.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function promote(string $caseId): array {
		return $this->drafts->promote(caseId: $caseId);
	}//end promote()

	/**
	 * Record which required fields were knowingly left empty.
	 *
	 * @param string $caseId The case UUID.
	 * @param list<string> $fields The field names left empty.
	 *
	 * @return array<string, mixed> How the case now reads.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function recordIncompleteness(string $caseId, array $fields): array {
		return $this->incompleteness->record(caseId: $caseId, missing: $fields);
	}//end recordIncompleteness()

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
}//end class
