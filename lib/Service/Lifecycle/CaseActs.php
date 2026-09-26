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
 * every method here is one line onto the class that owns the act.
 *
 * The read that goes with the acts is {@see CaseActsOverview}, and it is a
 * separate class on purpose: it was a method here until 2026-09-20, and it
 * was the only thing that needed all eleven collaborators at once. Holding
 * them for it made this seam a class with eleven constructor parameters and
 * eleven public methods, suppressed twice. The commands need four.
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

/**
 * One seam over the classes that own the acts on a case.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
class CaseActs {

	/**
	 * Constructor.
	 *
	 * @param CaseEndingActs $endings Finish, abort and archive.
	 * @param CaseHoldActs $holds Hold and release.
	 * @param DraftCaseActs $drafts Begin and promote.
	 * @param CaseIncompleteness $incompleteness Record what is missing.
	 */
	public function __construct(
		private readonly CaseEndingActs $endings,
		private readonly CaseHoldActs $holds,
		private readonly DraftCaseActs $drafts,
		private readonly CaseIncompleteness $incompleteness,
	) {
	}//end __construct()

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
}//end class
