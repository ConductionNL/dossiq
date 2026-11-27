<?php

/**
 * Dossiq CaseSessionController.
 *
 * `GET /api/cases/{caseId}/sessions`: the sessions the case's case type
 * declares (`caseType.sessions[]`) and the sessions the case holds.
 * `POST /api/cases/{caseId}/sessions`: plan one of the declared sessions,
 * keeping its inspection floor and invitee roles. The case page's Plan a
 * session action reads the first and posts the second, whatever the case
 * type calls its session (a hearing, a meeting with the parties, ...).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/complaints-are-a-case-type/tasks.md#2.3
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\SessionServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Throwable;

/**
 * The declared sessions of a case: read them, and plan one.
 *
 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-planning-a-session-keeps-the-inspection-floor-and-the-invitee-roles-req-css-2
 */
class CaseSessionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                   $appName     The app name.
	 * @param IRequest                 $request     The request.
	 * @param SessionServiceInterface  $sessions    Reads and plans the sessions.
	 * @param IUserSession             $userSession The user session.
	 * @param CaseAccessGuardInterface $caseAccess  Per-case authorization guard.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SessionServiceInterface $sessions,
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuardInterface $caseAccess,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The sessions the case's case type declares and the sessions the case holds.
	 *
	 * A case the user may not read answers 403 before anything is read.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-a-case-type-declares-the-sessions-a-case-holds-req-css-1
	 */
	#[NoAdminRequired]
	public function index(string $caseId): JSONResponse {
		$caseId = trim($caseId);
		$user   = $this->userSession->getUser();
		if ($user === null || $this->caseAccess->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'You may not read this case.'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse($this->sessions->sessionsFor(caseId: $caseId));
		} catch (Throwable) {
			return new JSONResponse(['message' => 'The sessions of this case could not be read. Try again later.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end index()

	/**
	 * Plan a session the case type declares, chaired by the caller unless the body names someone.
	 *
	 * A case the user may not change answers 403 before anything is written. A
	 * plan the session's rules refuse (not declared, inside the inspection
	 * floor, an invitee role it does not invite) answers 400 with the rule's
	 * sentence.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/case-session-with-parties-and-minutes/specs/case-session-with-parties-and-minutes/spec.md#requirement-planning-a-session-keeps-the-inspection-floor-and-the-invitee-roles-req-css-2
	 */
	#[NoAdminRequired]
	public function create(string $caseId): JSONResponse {
		$caseId = trim($caseId);
		$user   = $this->userSession->getUser();
		if ($user === null || $this->caseAccess->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'You may not change this case.'], Http::STATUS_FORBIDDEN);
		}

		$invitees = $this->request->getParam('invitees', []);
		$payload  = $this->request->getParam('payload', []);
		if (is_array($invitees) === false || is_array($payload) === false) {
			return new JSONResponse(['message' => 'Send the invitees as a list and the payload as an object.'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$session = $this->sessions->schedule(
				caseId: $caseId,
				sessionKey: trim((string)$this->request->getParam('sessionKey', '')),
				scheduledDate: trim((string)$this->request->getParam('scheduledDate', '')),
				chairpersonId: $this->chairpersonOf(user: $user),
				invitees: array_values($invitees),
				payload: $payload,
			);
		} catch (InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (Throwable) {
			return new JSONResponse(['message' => 'The session could not be planned. Try again later.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($session, Http::STATUS_CREATED);
	}//end create()

	/**
	 * Who chairs the session: the one the body names, else the caller.
	 *
	 * @param IUser $user The caller.
	 *
	 * @return string The chairperson's id.
	 */
	private function chairpersonOf(IUser $user): string {
		$named = trim((string)$this->request->getParam('chairpersonId', ''));
		if ($named !== '') {
			return $named;
		}

		return $user->getUID();
	}//end chairpersonOf()
}//end class
