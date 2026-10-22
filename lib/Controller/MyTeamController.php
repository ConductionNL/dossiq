<?php

/**
 * Dossiq My Team Controller
 *
 * Answers the current user's primary team, for the `@myTeam` filter token the
 * team queue opens on (board DqTeamwachtrij).
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
 * @spec openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Team\PrimaryTeamService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The current user's primary team.
 *
 * @spec openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles
 */
class MyTeamController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest           $request     The request.
	 * @param PrimaryTeamService $teams       Reads the team from the organisation roles.
	 * @param IUserSession       $userSession The user session.
	 */
	public function __construct(
		IRequest $request,
		private readonly PrimaryTeamService $teams,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The current user's primary team.
	 *
	 * @return JSONResponse `{groupId: string|null, name: string|null}`; both null for a user in no team.
	 *
	 * @spec openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		// Authorization guard: no user or object id is taken; the answer is
		// about the signed-in user only.
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		$team = $this->teams->teamOf(user: $user);

		return new JSONResponse(
			data: [
				'groupId' => $team['groupId'] ?? null,
				'name' => $team['name'] ?? null,
			]
		);
	}//end show()
}//end class
