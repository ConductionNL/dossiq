<?php

/**
 * Resolves a conflict record from the sync frame's conflict card.
 *
 * `POST /api/conflict-records/{id}/resolve` with `{ "resolution":
 * "client_wins" | "server_wins" }`. The resolution is read from the body and
 * checked here; who resolved it is always the session user, never a value
 * from the body. Every access check runs in ConflictResolutionService before
 * anything is written.
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
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\Contracts\ConflictResolutionServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The resolve endpoint of conflict records.
 *
 * @spec openspec/changes/inspection-conflict-card/tasks.md
 */
class ConflictRecordController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                    $appName     App id.
	 * @param IRequest                  $request     The request.
	 * @param ConflictResolutionServiceInterface $resolution  Checks access and writes the choice.
	 * @param IUserSession              $userSession The signed-in user.
	 *
	 * @spec openspec/changes/inspection-conflict-card/tasks.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly ConflictResolutionServiceInterface $resolution,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Keep the device's version or the office's.
	 *
	 * @param string $id The conflict record's id.
	 *
	 * @return JSONResponse The resolved record, or the refusal.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/inspection-conflict-card/specs/mobiel-inspectie-offline/spec.md
	 */
	#[NoAdminRequired]
	public function resolve(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Niet aangemeld.'], Http::STATUS_UNAUTHORIZED);
		}

		$resolution = $this->request->getParam('resolution');
		$allowed = [ConflictResolutionServiceInterface::CLIENT_WINS, ConflictResolutionServiceInterface::SERVER_WINS];
		if (is_string($resolution) === false || in_array($resolution, $allowed, true) === false) {
			return new JSONResponse(
				['message' => 'Kies client_wins of server_wins.'],
				Http::STATUS_BAD_REQUEST
			);
		}

		$outcome = $this->resolution->resolve(id: $id, resolution: $resolution, userId: $user->getUID());

		return new JSONResponse($outcome->body, $outcome->status);
	}//end resolve()
}//end class
