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
use Throwable;

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
	 * @return JSONResponse `{groupId: string|null, name: string|null, groupIds: string[], names: string[]}`: the
	 *                      primary team and every team of the user; nulls and empty lists for a user in no team;
	 *                      503 when the organisation roles cannot be read, so no team is guessed.
	 *
	 * @spec openspec/changes/my-team-queue/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-the-team-queue-is-the-unassigned-open-cases-of-the-readers-teams
	 */
	#[NoAdminRequired]
	public function show(): JSONResponse {
		// Authorization guard: no user or object id is taken; the answer is
		// about the signed-in user only.
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(data: ['message' => 'Not logged in'], statusCode: Http::STATUS_UNAUTHORIZED);
		}

		try {
			$teams = $this->teams->teamsOf(user: $user);
		} catch (Throwable) {
			return new JSONResponse(
				data: ['message' => 'The organisation roles could not be read'],
				statusCode: Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		$primary = ($teams[0] ?? null);
		return new JSONResponse(
			data: [
				'groupId'  => $primary['groupId'] ?? null,
				'name'     => $primary['name'] ?? null,
				'groupIds' => array_column($teams, 'groupId'),
				'names'    => array_column($teams, 'name'),
			]
		);
	}//end show()
}//end class
