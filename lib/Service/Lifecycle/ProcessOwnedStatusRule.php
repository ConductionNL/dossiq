<?php

/**
 * A case type may give its status to the process.
 *
 * Valtimo's position is that the process owns the status and no handler sets
 * it by hand. It is the opposite of dossiq's, where a status is a field a
 * handler with the right can write, and the lane that found it said so: "it is
 * a deliberate design position and the opposite of ours, which is worth
 * recording as a row rather than a preference".
 *
 * So it is a CASE TYPE DECLARATION rather than a product decision. A case type
 * that declares it accepts no hand-set status and every move goes through a
 * transition; a case type that does not keeps today's behaviour exactly. A
 * gemeente running both kinds is the normal case, which is precisely why this
 * could not be a setting.
 *
 * 🔴 AN UNRESOLVABLE PROCESS REFUSES THE WRITE, PER ADR-102. A case type that
 * declares process-owned status and whose workflow definition cannot be
 * resolved is a case type whose rule cannot be evaluated, and config absence
 * fails closed. Falling through to the hand-set would mean the strictest case
 * types silently became the loosest the moment their process went missing,
 * which is the failure mode that is impossible to notice from the outside.
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
use OCA\Dossiq\Service\Transitions\CaseStatusStore;

/**
 * Refuses a direct status write where the case type says the process owns it.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
 */
class ProcessOwnedStatusRule {

	/**
	 * Constructor.
	 *
	 * @param LifecycleCaseTypeRules $rules Reads the case type's declaration.
	 * @param CaseStatusStore $store Resolves a case to its type, for the caseId form.
	 */
	public function __construct(
		private readonly LifecycleCaseTypeRules $rules,
		private readonly CaseStatusStore $store,
	) {
	}//end __construct()

	/**
	 * Refuse a hand-set status on one case, resolving its type first.
	 *
	 * The caseId form exists so the caller does not have to load the case to
	 * ask the question. A caller that loaded the case in order to find out
	 * whether it may write to it has already done the write's own read, and
	 * the two would then have to be kept in step.
	 *
	 * 🔴 A CASE THAT CANNOT BE READ DOES NOT REFUSE HERE. The write path
	 * behind this refuses an unreadable case on its own, with its own message,
	 * and a second not-found from a rule about something else would tell the
	 * caller the wrong thing about why their request failed.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the process owns the status on this case's type.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function requireHandSetAllowedOn(string $caseId): void {
		$case = $this->store->loadCase(caseId: $caseId);
		if ($case === null) {
			return;
		}

		$this->requireHandSetAllowed(caseTypeId: (string)($case['caseType'] ?? ''));
	}//end requireHandSetAllowedOn()

	/**
	 * Refuse a hand-set status where this case type does not accept one.
	 *
	 * Called by the write path, never by the transition path. A transition IS
	 * the process moving the status, so putting this check inside the engine
	 * would refuse the one way a process-owned status is allowed to move.
	 *
	 * @param string $caseTypeId The case's caseType UUID.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the process owns the status, or when the
	 *                          declaration cannot be evaluated.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function requireHandSetAllowed(string $caseTypeId): void {
		if ($this->rules->processOwnsStatus(caseTypeId: $caseTypeId) === false) {
			return;
		}

		if ($this->rules->processOf(caseTypeId: $caseTypeId) === '') {
			throw RefusedException::indeterminate(
				rule: 'process-owned-status-unresolvable',
				sentence: 'This case type gives its status to a process that could not be found, '
					.'so the status cannot be set by hand or by the process.',
			);
		}

		throw new RefusedException(
			rule: 'process-owns-the-status',
			sentence: 'This case type gives its status to the process. Use a transition instead.',
			status: RefusedException::STATUS_REFUSED,
		);
	}//end requireHandSetAllowed()

	/**
	 * Whether a hand-set status is accepted on this case type.
	 *
	 * The same question as {@see self::requireHandSetAllowed()} without the
	 * throw, for the menu: an act that is not permitted is shown and disabled
	 * with the reason, and a menu cannot be built out of exceptions.
	 *
	 * @param string $caseTypeId The case's caseType UUID.
	 *
	 * @return bool True when a status may be written directly.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function allowsHandSet(string $caseTypeId): bool {
		return ($this->rules->processOwnsStatus(caseTypeId: $caseTypeId) === false);
	}//end allowsHandSet()
}//end class
