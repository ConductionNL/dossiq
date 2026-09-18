<?php

/**
 * Asking the holder for a case, and answering.
 *
 * The pull, beside the push. `CaseHandoverController` moves a case away from
 * the person who has it; this one asks the person who has it for it, and the
 * answer is theirs to give.
 *
 * 🔴 THE TWO GUARDS ARE DIFFERENT ON PURPOSE. Asking needs READ access: the
 * asker does not hold the case, so requiring mutation access would refuse
 * exactly the people the request exists for. Answering needs MUTATION access,
 * because an accept moves the case, and letting anyone who can read a case
 * accept a request on it would be a way to take somebody's work off them
 * without ever handing it over (ADR-005 Rule 3).
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
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Custody\CaseTakeoverRequest;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Ask, accept, refuse, and read what was asked.
 *
 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
 */
class CaseTakeoverController extends Controller {

	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param string              $appName     The app name.
	 * @param IRequest            $request     The request.
	 * @param CaseTakeoverRequest $takeovers   The pull and its answers.
	 * @param CaseAccessGuard     $accessGuard Per-case authorization, failing closed.
	 * @param IUserSession        $userSession The session.
	 * @param LoggerInterface     $logger      The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseTakeoverRequest $takeovers,
		private readonly CaseAccessGuard $accessGuard,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Ask the holder for a case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse The request, or the refusal.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	#[NoAdminRequired]
	public function ask(string $caseId): JSONResponse {
		$user = $this->readerOf(caseId: $caseId);
		if ($user === null) {
			return $this->notYours();
		}

		$reason = trim((string)$this->request->getParam('reason', ''));
		if ($reason === '') {
			return new JSONResponse(
				['message' => 'Say why you should have the case.', 'error' => 'takeover-reason-missing'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			return new JSONResponse(
				$this->takeovers->request(caseId: $caseId, requestedBy: $user->getUID(), reason: $reason)
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'takeover ask', e: $e);
		}
	}//end ask()

	/**
	 * Accept: the case goes to the asker.
	 *
	 * @param string $caseId     The case uuid.
	 * @param string $takeoverId The request's uuid.
	 *
	 * @return JSONResponse The answered request, or the refusal.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	#[NoAdminRequired]
	public function accept(string $caseId, string $takeoverId): JSONResponse {
		$user = $this->writerOf(caseId: $caseId);
		if ($user === null) {
			return $this->notYours();
		}

		try {
			return new JSONResponse(
				$this->takeovers->accept(takeoverId: $takeoverId, acceptedBy: $user->getUID())
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'takeover accept', e: $e);
		}
	}//end accept()

	/**
	 * Refuse: the case stays, and the reason is recorded.
	 *
	 * @param string $caseId     The case uuid.
	 * @param string $takeoverId The request's uuid.
	 *
	 * @return JSONResponse The answered request, or the refusal.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	#[NoAdminRequired]
	public function refuse(string $caseId, string $takeoverId): JSONResponse {
		$user = $this->writerOf(caseId: $caseId);
		if ($user === null) {
			return $this->notYours();
		}

		$reason = trim((string)$this->request->getParam('reason', ''));
		if ($reason === '') {
			return new JSONResponse(
				['message' => 'A refusal without a reason is not an answer.', 'error' => 'takeover-reason-missing'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			return new JSONResponse(
				$this->takeovers->refuse(takeoverId: $takeoverId, reason: $reason, refusedBy: $user->getUID())
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'takeover refuse', e: $e);
		}
	}//end refuse()

	/**
	 * Every request made on a case, newest first.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse The requests, or the refusal.
	 *
	 * @spec openspec/changes/custody-and-handover-of-a-case/specs/case-management/spec.md#requirement-a-colleague-may-ask-the-holder-for-a-case-req-cus-02
	 */
	#[NoAdminRequired]
	public function on(string $caseId): JSONResponse {
		if ($this->readerOf(caseId: $caseId) === null) {
			return $this->notYours();
		}

		$requests = $this->takeovers->on(caseId: $caseId);

		return new JSONResponse(['caseId' => $caseId, 'requests' => $requests, 'total' => count($requests)]);
	}//end on()

	/**
	 * The caller, when they may read this case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return IUser|null The caller, or null.
	 */
	private function readerOf(string $caseId): ?IUser {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		if ($this->accessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return null;
		}

		return $user;
	}//end readerOf()

	/**
	 * The caller, when they may move this case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return IUser|null The caller, or null.
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
	 * One answer for "this case is not yours", so none of the four differ.
	 *
	 * @return JSONResponse The refusal.
	 */
	private function notYours(): JSONResponse {
		return new JSONResponse(
			['message' => 'You cannot do that on this case.', 'error' => 'case-access-denied'],
			Http::STATUS_FORBIDDEN,
		);
	}//end notYours()
}//end class
