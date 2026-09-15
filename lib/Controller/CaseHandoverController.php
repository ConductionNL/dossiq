<?php

/**
 * Handing a case to another team, accepting it, and refusing it back.
 *
 * The federated zaakoverdracht answers on `/api/transfers` in
 * {@see CaseSharingController}, and stays there. This controller is the
 * internal half, on `/api/case/{caseId}/handover`, because the act addresses a
 * CASE rather than a transfer resource and because the guard is different: the
 * federated path authorises a partner organisation over a scoped token, and
 * this one authorises a person who may already write the case.
 *
 * 🔴 EVERY ENDPOINT CHECKS THE CASE, NOT THE ROLE. `#[NoAdminRequired]` with
 * no per-object guard is an IDOR, and handing somebody else's case to a team
 * they cannot reach is a good way to lose it. {@see CaseAccessGuard} answers
 * per case and fails closed (ADR-005 Rule 3).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseTransferService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The internal handover: hand on, accept, refuse back, and what is outstanding.
 *
 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
 */
class CaseHandoverController extends Controller {

	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param string              $appName     The app name.
	 * @param IRequest            $request     The request.
	 * @param CaseTransferService $transfers   The one door to every case move.
	 * @param CaseAccessGuard     $accessGuard Per-case authorization, failing closed.
	 * @param IGroupManager       $groups      The teams a caller belongs to.
	 * @param IUserSession        $userSession The session.
	 * @param LoggerInterface     $logger      The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseTransferService $transfers,
		private readonly CaseAccessGuard $accessGuard,
		private readonly IGroupManager $groups,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Hand a case to another team.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse The transfer record, or the refusal.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-a-case-is-handed-to-another-team-as-a-recorded-act-req-hand-01
	 */
	#[NoAdminRequired]
	public function hand(string $caseId): JSONResponse {
		$user = $this->writerOf(caseId: $caseId);
		if ($user === null) {
			return $this->notYours();
		}

		$team = trim((string)$this->request->getParam('team', ''));
		$reason = trim((string)$this->request->getParam('reason', ''));
		if ($team === '' || $reason === '') {
			return new JSONResponse(
				['message' => 'Name the team and say why the case is moving.', 'error' => 'handover-incomplete'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			return new JSONResponse(
				$this->transfers->handToTeam(
					caseId: $caseId,
					targetTeam: $team,
					reason: $reason,
					initiatedBy: $user->getUID(),
					doorzending: ($this->request->getParam('doorzending', false) === true),
				)
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'hand', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'hand', e: $e);
		}
	}//end hand()

	/**
	 * Accept a handover on the receiving team's behalf.
	 *
	 * @param string $caseId     The case uuid, which is what the guard needs.
	 * @param string $transferId The handover's uuid.
	 *
	 * @return JSONResponse The settled record, or the refusal.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	#[NoAdminRequired]
	public function accept(string $caseId, string $transferId): JSONResponse {
		$user = $this->writerOf(caseId: $caseId);
		if ($user === null) {
			return $this->notYours();
		}

		try {
			return new JSONResponse(
				$this->transfers->acceptHandover(transferId: $transferId, acceptedBy: $user->getUID())
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'accept', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'accept', e: $e);
		}
	}//end accept()

	/**
	 * Refuse a handover back, with a reason.
	 *
	 * @param string $caseId     The case uuid.
	 * @param string $transferId The handover's uuid.
	 *
	 * @return JSONResponse The settled record, or the refusal.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	#[NoAdminRequired]
	public function refuse(string $caseId, string $transferId): JSONResponse {
		$user = $this->writerOf(caseId: $caseId);
		if ($user === null) {
			return $this->notYours();
		}

		$reason = trim((string)$this->request->getParam('reason', ''));
		if ($reason === '') {
			return new JSONResponse(
				['message' => 'Say why the team will not take this case.', 'error' => 'refusal-needs-a-reason'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			return new JSONResponse(
				$this->transfers->refuseHandover(transferId: $transferId, reason: $reason, refusedBy: $user->getUID())
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'refuse', e: $e);
		} catch (Throwable $e) {
			return $this->broke(op: 'refuse', e: $e);
		}
	}//end refuse()

	/**
	 * The handovers a team sent that nobody has picked up.
	 *
	 * Scoped to a team the caller is actually in: the outstanding list names
	 * cases, and an unscoped read would hand any signed-in user the number and
	 * title of every case any team ever passed on.
	 *
	 * @param string $team The sending team's Nextcloud group id.
	 *
	 * @return JSONResponse The outstanding handovers.
	 *
	 * @spec openspec/changes/handing-a-case-over/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
	 */
	#[NoAdminRequired]
	public function outstanding(string $team): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->notYours();
		}

		if (in_array($team, $this->teamsOf(user: $user), true) === false) {
			return new JSONResponse(
				['message' => 'You are not in that team.', 'error' => 'not-in-the-team'],
				Http::STATUS_FORBIDDEN,
			);
		}

		return new JSONResponse(['results' => $this->transfers->outstandingHandovers(team: $team)]);
	}//end outstanding()

	/**
	 * The caller, when they may write this case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return IUser|null The caller, or null when they may not.
	 */
	private function writerOf(string $caseId): ?IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		if ($this->accessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return null;
		}

		return $user;
	}//end writerOf()

	/**
	 * The teams a caller belongs to.
	 *
	 * @param IUser $user The caller.
	 *
	 * @return array<int, string> The group ids.
	 */
	private function teamsOf(IUser $user): array {
		return array_map('strval', $this->groups->getUserGroupIds($user));
	}//end teamsOf()

	/**
	 * One answer for "this case is not yours", so none of the four differ.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function notYours(): JSONResponse {
		return new JSONResponse(
			['message' => 'You cannot move this case.', 'error' => 'case-access-denied'],
			Http::STATUS_FORBIDDEN,
		);
	}//end notYours()

	/**
	 * A failure that is not a refusal, logged and answered as one.
	 *
	 * @param string    $op The endpoint, for the log line.
	 * @param Throwable $e  The failure.
	 *
	 * @return JSONResponse The answer.
	 */
	private function broke(string $op, Throwable $e): JSONResponse {
		$this->logger->error('CaseHandoverController: ' . $op . ' failed', ['exception' => $e->getMessage()]);

		return new JSONResponse(
			['message' => 'The handover could not be completed.', 'error' => 'handover-failed'],
			Http::STATUS_INTERNAL_SERVER_ERROR,
		);
	}//end broke()
}//end class
