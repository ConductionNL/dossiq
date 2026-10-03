<?php

/**
 * Dossiq Planned Action Controller.
 *
 * What happens next on a case, and the one gesture that moves it on
 * (gap register row 3.28).
 *
 * READING the planned actions of a case does NOT go through here. They are
 * ordinary OpenRegister objects and the case page reads them the way it reads
 * every other collection on it, so a controller for that would be the
 * pass-through ADR-022 exists to refuse. What needs a controller is
 * COMPLETING one, because completing is not a write of a field: it closes one
 * record and schedules another from a declaration on a third, and a client
 * doing that in three calls can leave a case with a completed action and no
 * successor when the second call fails.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\PlannedAction\PlannedActionService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The planned next action of a case.
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
 */
class PlannedActionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param PlannedActionService $plannedActions Reads and writes planned actions.
	 * @param IUserSession $userSession The user session.
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed).
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly PlannedActionService $plannedActions,
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * What happens next on this case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse The next planned action, or `{"next": null}`.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	#[NoAdminRequired]
	public function next(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// The per-case guard, not a bare authentication check: what is planned
		// on a case, by whom and when, is case content.
		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['next' => $this->plannedActions->nextFor(caseId: $caseId)]);
	}//end next()

	/**
	 * Complete a planned action, and plan whatever follows it.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $actionId The planned action UUID.
	 *
	 * @return JSONResponse The action planned next, or `{"next": null}` when the chain ends.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/task-management/spec.md
	 */
	#[NoAdminRequired]
	public function complete(string $caseId, string $actionId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// MUTATION access, not read: completing an action changes what the
		// case says happens next, and a reader of the case is not thereby
		// somebody who may decide that.
		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		$action = null;
		foreach ($this->plannedActions->plannedOn(caseId: $caseId) as $candidate) {
			$candidateId = (string)($candidate['id'] ?? ($candidate['uuid'] ?? ''));
			if ($candidateId === $actionId) {
				$action = $candidate;
				break;
			}
		}

		// The action is looked up WITHIN this case rather than by id alone.
		// Fetching it by id and trusting the `case` on it would let a caller
		// complete another case's action by naming a case they may write, and
		// the guard above would have answered about the wrong case.
		if ($action === null) {
			return new JSONResponse(
				['error' => 'No such planned action on this case'],
				Http::STATUS_NOT_FOUND
			);
		}

		$next = $this->plannedActions->complete(
			action: $action,
			completedBy: $user->getUID(),
			roleHolders: []
		);

		return new JSONResponse(['next' => $next]);
	}//end complete()
}//end class
