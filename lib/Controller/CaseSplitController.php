<?php

/**
 * Dividing one case into two.
 *
 * The inverse of `CaseMergeController`, and deliberately not part of it: a
 * merge ends a case and a split opens one, so they refuse for different reasons
 * and are guarded at different moments.
 *
 * 🔴 EVERY ENDPOINT CHECKS THE CASE, NOT THE ROLE. A split moves documents and
 * parties off a case, so it needs the same authority as editing it.
 * `#[NoAdminRequired]` with no per-object guard is an IDOR, and this one would
 * let anybody move somebody else's file onto a case of their own
 * ({@see CaseAccessGuard}, ADR-005 Rule 3). The service checks the chosen ids
 * against the case a second time, because an id is not an authorisation.
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
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Split\CaseSplitService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Split a case, and read what its type allows a split to divide.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class CaseSplitController extends Controller {

	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param string           $appName     The app name.
	 * @param IRequest         $request     The request.
	 * @param CaseSplitService $splits      The division itself.
	 * @param CaseAccessGuard  $accessGuard Per-case authorization, failing closed.
	 * @param IUserSession     $userSession The session.
	 * @param LoggerInterface  $logger      The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseSplitService $splits,
		private readonly CaseAccessGuard $accessGuard,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Split the case, moving what was chosen.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse The new case and what moved, or the refusal.
	 *
	 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md#requirement-a-case-splits-by-moving-what-was-chosen-req-spl-01
	 */
	#[NoAdminRequired]
	public function split(string $caseId): JSONResponse {
		$user = $this->writerOf(caseId: $caseId);
		if ($user === null) {
			return $this->notYours();
		}

		$title = trim((string)$this->request->getParam('title', ''));
		$chosen = [
			'documents' => (array)$this->request->getParam('documents', []),
			'parties' => (array)$this->request->getParam('parties', []),
			'tasks' => (array)$this->request->getParam('tasks', []),
			'partiesOnBoth' => (array)$this->request->getParam('partiesOnBoth', []),
		];

		try {
			return new JSONResponse(
				$this->splits->split(
					caseId: $caseId,
					title: $title,
					chosen: $chosen,
					actor: $user->getUID(),
				)
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'case split', e: $e);
		}
	}//end split()

	/**
	 * The caller, when they may move this case's material.
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
	 * One answer for "this case is not yours".
	 *
	 * @return JSONResponse The refusal.
	 */
	private function notYours(): JSONResponse {
		return new JSONResponse(
			['message' => 'You cannot split this case.', 'error' => 'case-access-denied'],
			Http::STATUS_FORBIDDEN,
		);
	}//end notYours()
}//end class
