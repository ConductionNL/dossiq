<?php

/**
 * Who may read a figure about the whole case population.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Reporting;

use OCP\IUser;
use OCP\IGroupManager;
use Throwable;

/**
 * The gate a fleet-wide reporting endpoint asks before it answers.
 *
 * 🔴 THE GROUPS ARE NOT NEW. They are the three
 * `ProcessMiningController::ALLOWED_GROUPS` has gated on since that report
 * shipped, and the same three the retired IV3 report used. This class exists
 * because the SHAPE was copied into one controller and not the others, not
 * because the vocabulary needed inventing: a second list of group names is a
 * second answer to one question, and the two disagree the first week somebody
 * edits one of them.
 *
 * 🔴 WHAT SEPARATES A REPORTING ENDPOINT FROM AN ORDINARY ONE, because the
 * distinction decides who gets this gate and who must not. A reporting endpoint
 * answers an AGGREGATE over cases the caller was never granted: a median dwell
 * time across the organisation, a quarterly compliance figure, an annual
 * dwangsom statement. An ordinary endpoint answers about objects, and
 * OpenRegister already refuses the ones the caller may not read, so putting a
 * group gate in front of it would take a handler's own work away from them.
 *
 * `admin` is Nextcloud's administrator check rather than a group to resolve,
 * and is kept for the same defensive reason every sibling gate keeps it: an
 * instance that has not yet named its controllers still has somebody who can
 * read the report and fix the configuration.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Reporting
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/security-hardening/spec.md
 */
class ReportingAudience {

	/**
	 * The groups that may read a figure about the whole case population.
	 *
	 * Verbatim from `ProcessMiningController::ALLOWED_GROUPS`, which is the
	 * attested precedent in this app.
	 *
	 * @var array<int, string>
	 */
	public const ALLOWED_GROUPS = ['controllers', 'beheerders', 'admin'];

	/**
	 * The sentence a refused caller reads.
	 */
	public const REFUSAL = 'This report covers every case, so it is for the controller and beheerder roles.';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groupManager Resolves the caller's membership.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IGroupManager $groupManager,
	) {
	}//end __construct()

	/**
	 * Whether this caller is in the audience for a fleet-wide report.
	 *
	 * It reads GROUP MEMBERSHIP and never an object grant, which is why it is
	 * not named `mayRead`: a report is about the fleet and has no object whose
	 * grants could answer, and a method with an evaluator's name here reads as
	 * a second answer to a question OpenRegister owns.
	 *
	 * @param IUser|null $user The caller, null for no session.
	 *
	 * @return bool True when they hold one of the roles.
	 *
	 * @spec openspec/specs/security-hardening/spec.md
	 */
	public function isInAudience(?IUser $user): bool {
		if ($user === null) {
			return false;
		}

		$uid = $user->getUID();
		if ($uid === '') {
			return false;
		}

		try {
			foreach (self::ALLOWED_GROUPS as $group) {
				if ($group === 'admin') {
					continue;
				}

				if ($this->groupManager->isInGroup($uid, $group) === true) {
					return true;
				}
			}

			return $this->groupManager->isAdmin($uid);
		} catch (Throwable $e) {
			// An unresolvable group check is not an authorization.
			return false;
		}
	}//end isInAudience()
}//end class
