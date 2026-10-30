<?php

/**
 * MyTeamController: answers the signed-in user's own team, never another user's.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/my-team/spec.md#requirement-the-current-users-primary-team-is-read-from-the-organisation-roles
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\MyTeamController;
use OCA\Dossiq\Service\Team\PrimaryTeamService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * The team endpoint behind the `@myTeam` filter token.
 *
 * @covers \OCA\Dossiq\Controller\MyTeamController
 */
class MyTeamControllerTest extends TestCase {

	/**
	 * Build the controller over a session holding the given user.
	 *
	 * @param IUser|null         $user  The signed-in user, or null.
	 * @param PrimaryTeamService $teams The team service.
	 *
	 * @return MyTeamController
	 */
	private function controller(?IUser $user, PrimaryTeamService $teams): MyTeamController {
		$session = $this->createMock(originalClassName: IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new MyTeamController(
			request: $this->createMock(originalClassName: IRequest::class),
			teams: $teams,
			userSession: $session,
		);
	}//end controller()

	/**
	 * No signed-in user: 401, and the team service is not asked.
	 *
	 * @return void
	 */
	public function testNoUserIsUnauthorisedAndAsksNothing(): void {
		$teams = $this->createMock(originalClassName: PrimaryTeamService::class);
		$teams->expects($this->never())->method('teamsOf');

		$response = $this->controller(user: null, teams: $teams)->show();

		$this->assertSame(expected: Http::STATUS_UNAUTHORIZED, actual: $response->getStatus());
	}//end testNoUserIsUnauthorisedAndAsksNothing()

	/**
	 * The signed-in user's team is answered as groupId and name.
	 *
	 * @return void
	 */
	public function testTheSignedInUsersTeamIsAnswered(): void {
		$user  = $this->createMock(originalClassName: IUser::class);
		$teams = $this->createMock(originalClassName: PrimaryTeamService::class);
		$teams->expects($this->once())->method('teamsOf')->with($user)
			->willReturn([
				['groupId' => 'zuiddrecht-woo-en-informatie', 'name' => 'Informatie en Woo'],
				['groupId' => 'zuiddrecht-vergunningen', 'name' => 'Vergunningen'],
			]);

		$response = $this->controller(user: $user, teams: $teams)->show();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
		$this->assertSame(
			expected: [
				'groupId'  => 'zuiddrecht-woo-en-informatie',
				'name'     => 'Informatie en Woo',
				'groupIds' => ['zuiddrecht-woo-en-informatie', 'zuiddrecht-vergunningen'],
				'names'    => ['Informatie en Woo', 'Vergunningen'],
			],
			actual: $response->getData()
		);
	}//end testTheSignedInUsersTeamIsAnswered()

	/**
	 * Roles that cannot be read answer 503, not a guessed or empty team.
	 *
	 * @return void
	 */
	public function testUnreadableRolesAnswerServiceUnavailable(): void {
		$teams = $this->createMock(originalClassName: PrimaryTeamService::class);
		$teams->method('teamsOf')->willThrowException(new \RuntimeException('store down'));

		$response = $this->controller(user: $this->createMock(originalClassName: IUser::class), teams: $teams)->show();

		$this->assertSame(expected: Http::STATUS_SERVICE_UNAVAILABLE, actual: $response->getStatus());
	}//end testUnreadableRolesAnswerServiceUnavailable()

	/**
	 * A user in no team gets both fields null, never another team.
	 *
	 * @return void
	 */
	public function testAUserInNoTeamGetsNulls(): void {
		$teams = $this->createMock(originalClassName: PrimaryTeamService::class);
		$teams->method('teamsOf')->willReturn([]);

		$response = $this->controller(user: $this->createMock(originalClassName: IUser::class), teams: $teams)->show();

		$this->assertSame(expected: ['groupId' => null, 'name' => null, 'groupIds' => [], 'names' => []], actual: $response->getData());
	}//end testAUserInNoTeamGetsNulls()
}//end class
