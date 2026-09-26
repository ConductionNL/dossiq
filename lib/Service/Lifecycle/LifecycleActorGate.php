<?php

/**
 * Whether this caller may perform this act, and which role they are missing.
 *
 * An act a handler may not perform is shown and disabled with the reason,
 * never hidden: a hidden act teaches nobody why. So the gate has to answer
 * two questions rather than one. May they? And if not, what would they need?
 * A boolean can only carry the first, and a menu built on a boolean says
 * "Archive" greyed out with nothing beside it, which is the same dead end as
 * hiding it.
 *
 * 🔑 THE ROLE IS DECLARED PER CASE TYPE, not per product. A melding and a
 * bezwaar do not need the same seniority behind an abort, and the pattern is
 * already in the app: `caseType.destructionRole` gates destroying a case the
 * same way. This reads `finishingRole`, `abortingRole` and `archivingRole`.
 *
 * 🔴 AN UNDECLARED ROLE IS NOT THE SAME ANSWER FOR EVERY ACT, and the
 * difference is deliberate. Finishing and aborting fall OPEN when the case
 * type names no group: that is today's behaviour, every handler with access to
 * the case can close it, and silently locking that out on upgrade would strand
 * every case in every gemeente running this app. Archiving falls CLOSED to an
 * administrator, because it commits a retention rule and the recycle window's
 * destroying act already answers that way.
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
use OCP\IGroupManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The per-act, per-case-type role gate, with the missing role named.
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
 */
class LifecycleActorGate {

	/**
	 * The acts that refuse when the case type declares no group.
	 *
	 * @var list<string>
	 */
	private const CLOSED_WITHOUT_A_ROLE = ['archive'];

	/**
	 * Constructor.
	 *
	 * @param LifecycleCaseTypeRules $rules Reads the case type's declared roles.
	 * @param IGroupManager $groupManager Answers group membership.
	 * @param IUserSession $userSession Names the caller.
	 * @param LoggerInterface $logger Records a check that could not be made.
	 */
	public function __construct(
		private readonly LifecycleCaseTypeRules $rules,
		private readonly IGroupManager $groupManager,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The role this act needs on this case, or the empty string when it needs none.
	 *
	 * @param string $act One of `finish`, `abort`, `archive`.
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return string The group id.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function roleFor(string $act, array $case): string {
		return $this->rules->roleFor(caseTypeId: (string)($case['caseType'] ?? ''), act: $act);
	}//end roleFor()

	/**
	 * Whether the caller may perform this act on this case.
	 *
	 * @param string $act One of `finish`, `abort`, `archive`.
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return bool True when the act is permitted.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function may(string $act, array $case): bool {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return false;
		}

		$uid = $user->getUID();
		if ($this->isAdmin(uid: $uid) === true) {
			return true;
		}

		$role = $this->roleFor(act: $act, case: $case);
		if ($role === '') {
			return (in_array($act, self::CLOSED_WITHOUT_A_ROLE, true) === false);
		}

		try {
			return $this->groupManager->isInGroup($uid, $role);
		} catch (Throwable $e) {
			$this->logger->warning(
				'LifecycleActorGate: the role check could not be made',
				['act' => $act, 'role' => $role, 'exception' => $e->getMessage()],
			);

			return false;
		}
	}//end may()

	/**
	 * Refuse the act when the caller may not perform it, naming the role.
	 *
	 * The sentence names the group because the handler's next move depends on
	 * it: "you may not archive this case" ends the conversation, and "archiving
	 * this case type needs the archivaris group" starts one with whoever grants
	 * it.
	 *
	 * @param string $act One of `finish`, `abort`, `archive`.
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the caller may not perform the act.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function require(string $act, array $case): void {
		if ($this->may(act: $act, case: $case) === true) {
			return;
		}

		throw new RefusedException(
			rule: $act.'-role-required',
			sentence: $this->refusalSentence(act: $act, case: $case),
			status: RefusedException::STATUS_FORBIDDEN,
		);
	}//end require()

	/**
	 * The sentence a refused act carries.
	 *
	 * @param string $act The act.
	 * @param array<string, mixed> $case The loaded case.
	 *
	 * @return string The sentence, naming the role when one is declared.
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-management/spec.md
	 */
	public function refusalSentence(string $act, array $case): string {
		$role = $this->roleFor(act: $act, case: $case);
		if ($role !== '') {
			return sprintf('This act needs the %s group.', $role);
		}

		return 'This act needs an administrator.';
	}//end refusalSentence()

	/**
	 * Whether the caller is an administrator of this app or the instance.
	 *
	 * @param string $uid The caller's uid.
	 *
	 * @return bool True when they are.
	 *
	 * @spec exclude one membership read behind may(), which carries the requirement
	 */
	private function isAdmin(string $uid): bool {
		try {
			return $this->groupManager->isAdmin($uid);
		} catch (Throwable $e) {
			$this->logger->warning(
				'LifecycleActorGate: the admin check could not be made',
				['exception' => $e->getMessage()],
			);

			return false;
		}
	}//end isAdmin()
}//end class
