<?php

/**
 * The teams a case can be handed to, read from Nextcloud's groups.
 *
 * A team inside the organisation is a Nextcloud group, the same identifier
 * `assignedGroup` already carries and the same one `roleType.ncGroupId` binds a
 * role to. So this class resolves nothing of its own: it asks IGroupManager
 * whether a team exists and who is in it, and hands the answer on.
 *
 * 🔑 AN UNRESOLVABLE TEAM IS A REFUSAL, NEVER A DEFAULT. ADR-102 says an
 * absent configuration fails closed with a status. A handover to a group
 * nobody can name would take the case off the sending team and give it to
 * nothing, and the case would sit unowned with no one to ask about it. So
 * `require()` throws and the caller answers 422.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transfer
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
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transfer;

use OCA\Dossiq\Exception\RefusedException;
use OCP\IGroupManager;

/**
 * Resolves an internal team and answers who is in it.
 *
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */
class TeamDirectory {

	/**
	 * The rule slug a handover to an unknown team refuses under.
	 *
	 * @var string
	 */
	public const UNRESOLVABLE = 'handover-team-unresolvable';

	/**
	 * Constructor.
	 *
	 * @param IGroupManager $groups Nextcloud's group manager, the one authority on a team.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly IGroupManager $groups,
	) {
	}//end __construct()

	/**
	 * Whether this team exists on the instance.
	 *
	 * @param string $team The Nextcloud group id.
	 *
	 * @return bool True when the group is there.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
	 */
	public function exists(string $team): bool {
		$team = trim($team);
		if ($team === '') {
			return false;
		}

		return $this->groups->groupExists($team);
	}//end exists()

	/**
	 * The team, or a refusal naming the rule.
	 *
	 * @param string $team The Nextcloud group id the handover named.
	 *
	 * @return string The team, trimmed.
	 *
	 * @throws RefusedException When no such group exists.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 */
	public function require(string $team): string {
		$team = trim($team);
		if ($this->exists(team: $team) === false) {
			throw new RefusedException(
				rule: self::UNRESOLVABLE,
				sentence: 'That team could not be found, so the case stays where it is.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		return $team;
	}//end require()

	/**
	 * Whether a person is in a team.
	 *
	 * An unknown team answers false rather than throwing: this is asked while a
	 * seat is being reconciled, and by then the team has already been required.
	 *
	 * @param string $uid  The person's Nextcloud user id.
	 * @param string $team The Nextcloud group id.
	 *
	 * @return bool True when the person is a member.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/people-on-the-case/spec.md#requirement-a-case-carries-a-handler-and-a-coordinator-req-hand-05
	 */
	public function holds(string $uid, string $team): bool {
		$uid = trim($uid);
		$team = trim($team);
		if ($uid === '' || $team === '') {
			return false;
		}

		return $this->groups->isInGroup($uid, $team);
	}//end holds()
}//end class
