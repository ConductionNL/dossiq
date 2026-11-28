<?php

/**
 * Dossiq case version moves.
 *
 * The case side of a case type's versions: who may move a case, the versions
 * it could move to with the preview of one of them, and the move itself.
 * Split out of {@see \OCA\Dossiq\Controller\CaseVersionController}, which keeps
 * the version chain, closing a version, and the answers.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md#requirement-a-running-case-moves-to-another-version-only-as-a-named-act-req-zv-09
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\CaseVersionMoveInterface;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Moves a case along its case type's versions, for a caller who may write it.
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md#requirement-a-running-case-moves-to-another-version-only-as-a-named-act-req-zv-09
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class CaseVersionMoves {

	/**
	 * Constructor.
	 *
	 * @param CaseVersionMoveInterface $move        What a move would change, and the move.
	 * @param CaseAccessGuardInterface $accessGuard Per-case authorization, failing closed.
	 * @param IUserSession             $userSession The session.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly CaseVersionMoveInterface $move,
		private readonly CaseAccessGuardInterface $accessGuard,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The caller, when they may write this case, and null otherwise.
	 *
	 * Answering the USER rather than a boolean, because every caller that needs
	 * the check also needs the uid, and a guard that hands back a boolean
	 * invites a second `getUser()` beside it that nothing re-checks.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return IUser|null The caller, or null when they may not.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function writer(string $caseId): ?IUser {
		return (new CaseCaller(userSession: $this->userSession, accessGuard: $this->accessGuard))->writer(caseId: $caseId);
	}//end writer()

	/**
	 * The versions this case could move to, with the preview of the target when one is named.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $target The version to preview, or ''.
	 *
	 * @return array<string, mixed> The options, with `preview` when a target is named.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md#requirement-a-running-case-moves-to-another-version-only-as-a-named-act-req-zv-09
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function options(string $caseId, string $target): array {
		$answer = $this->move->options(caseId: $caseId);
		if ($target !== '') {
			$answer['preview'] = $this->move->preview(caseId: $caseId, targetCaseTypeId: $target);
		}

		return $answer;
	}//end options()

	/**
	 * Move the case onto the target version, as the caller.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $target The version to move to.
	 * @param string $reason Why, as the caller put it.
	 * @param IUser  $actor  Who moves it.
	 *
	 * @return array<string, mixed> What was applied.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md#requirement-a-running-case-moves-to-another-version-only-as-a-named-act-req-zv-09
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function move(string $caseId, string $target, string $reason, IUser $actor): array {
		return $this->move->move(caseId: $caseId, targetCaseTypeId: $target, reason: $reason, actorUid: $actor->getUID());
	}//end move()
}//end class
