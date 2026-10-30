<?php

/**
 * WorkQueueController Unit Tests
 *
 * Tests for the Dossiq WorkQueueController exposing the intelligent
 * work-queue endpoints (personal queue + coordinator workload).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/werkvoorraad-intelligent-queue/specs/werkvoorraad-intelligent-queue/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\WorkQueueController;
use OCA\Dossiq\Service\Substitution\CoveredWork;
use OCA\Dossiq\Service\Team\PrimaryTeamService;
use OCA\Dossiq\Service\WorkQueueService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Controller\WorkQueueController
 */
class WorkQueueControllerTest extends TestCase {

	/**
	 * @var IRequest|MockObject
	 */
	private IRequest $request;

	/**
	 * @var IUserSession|MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var IGroupManager|MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * @var WorkQueueService|MockObject
	 */
	private WorkQueueService $workQueueService;

	/**
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var IUserManager|MockObject
	 */
	private IUserManager $userManager;

	/**
	 * @var CoveredWork|MockObject
	 */
	private CoveredWork $coveredWork;

	/**
	 * @var PrimaryTeamService|MockObject
	 */
	private PrimaryTeamService $teams;

	private WorkQueueController $controller;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->workQueueService = $this->createMock(WorkQueueService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->coveredWork = $this->createMock(CoveredWork::class);
		$this->teams = $this->createMock(PrimaryTeamService::class);

		$this->controller = new WorkQueueController(
			$this->request,
			$this->userSession,
			$this->groupManager,
			$this->workQueueService,
			$this->logger,
			$this->userManager,
			$this->coveredWork,
			$this->teams,
		);
	}//end setUp()

	/**
	 * @param string $uid The user id.
	 *
	 * @return IUser|MockObject
	 */
	private function mockUser(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end mockUser()

	/**
	 * @return void
	 */
	public function testIndexReturns401WhenNotAuthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->index();

		self::assertSame(401, $response->getStatus());
		self::assertArrayHasKey('error', $response->getData());
	}//end testIndexReturns401WhenNotAuthenticated()

	/**
	 * Asked for covered work, the queue takes the cases the caller covers and counts them.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function testIndexWithCoveringAddsTheCoveredCases(): void {
		$user = $this->mockUser('pieter');
		$this->userSession->method('getUser')->willReturn($user);

		$covered = [['id' => 'cov-1', 'coveredFor' => ['absentee' => 'anouk', 'name' => 'Anouk Bakker', 'until' => '']]];
		$this->coveredWork->expects($this->once())->method('casesFor')->with($user)->willReturn($covered);
		$queue = [
			['id' => 'cov-1', 'itemType' => 'case', 'coveredFor' => $covered[0]['coveredFor']],
			['id' => 'own-1', 'itemType' => 'case', 'coveredFor' => null],
		];
		$this->workQueueService->expects($this->once())
			->method('computeQueue')
			->with('pieter', null, $covered)
			->willReturn($queue);

		$data = $this->controller->index(covering: '1')->getData();

		self::assertSame($queue, $data['items']);
		self::assertSame(1, $data['coveredCount']);
	}//end testIndexWithCoveringAddsTheCoveredCases()

	/**
	 * Without the toggle nobody else's work is read.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function testIndexWithoutCoveringReadsNoCoveredWork(): void {
		$this->userSession->method('getUser')->willReturn($this->mockUser('pieter'));
		$this->coveredWork->expects($this->never())->method('casesFor');
		$this->workQueueService->method('computeQueue')->with('pieter', null, [])->willReturn([]);

		self::assertSame(0, $this->controller->index()->getData()['coveredCount']);
	}//end testIndexWithoutCoveringReadsNoCoveredWork()

	/**
	 * @return void
	 */
	public function testIndexReturnsScopedQueueForAuthenticatedUser(): void {
		$user = $this->mockUser('jan');
		$this->userSession->method('getUser')->willReturn($user);

		$fixture = [['id' => 'case-1', 'itemType' => 'case', 'deadlineTier' => 'overdue', 'score' => 1005.0]];
		$this->workQueueService->expects($this->once())
			->method('computeQueue')
			->with('jan')
			->willReturn($fixture);

		$response = $this->controller->index();

		self::assertSame(200, $response->getStatus());
		$data = $response->getData();
		self::assertSame($fixture, $data['items']);
		self::assertArrayHasKey('computedAt', $data);
	}//end testIndexReturnsScopedQueueForAuthenticatedUser()

	/**
	 * @return void
	 */
	public function testIndexReturns500WhenServiceThrows(): void {
		$user = $this->mockUser('jan');
		$this->userSession->method('getUser')->willReturn($user);
		$this->workQueueService->method('computeQueue')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->index();

		self::assertSame(500, $response->getStatus());
	}//end testIndexReturns500WhenServiceThrows()

	/**
	 * @return void
	 */
	public function testWorkloadReturns401WhenNotAuthenticated(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$response = $this->controller->workload();

		self::assertSame(401, $response->getStatus());
	}//end testWorkloadReturns401WhenNotAuthenticated()

	/**
	 * @return void
	 */
	public function testWorkloadReturns403ForNonCoordinator(): void {
		$user = $this->mockUser('jan');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('jan')->willReturn(false);

		$this->workQueueService->expects($this->never())->method('computeWorkload');

		$response = $this->controller->workload();

		self::assertSame(403, $response->getStatus());
	}//end testWorkloadReturns403ForNonCoordinator()

	/**
	 * @return void
	 */
	public function testWorkloadReturns200ForCoordinator(): void {
		$user = $this->mockUser('admin-alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('admin-alice')->willReturn(true);

		$fixture = [['handler' => 'jan', 'openCaseCount' => 4], ['handler' => 'gone', 'openCaseCount' => 1]];
		$this->workQueueService->expects($this->once())
			->method('computeWorkload')
			->willReturn($fixture);
		$this->userManager->method('getDisplayName')->willReturnMap([['jan', 'Jan de Vries'], ['gone', null]]);

		$response = $this->controller->workload();

		self::assertSame(200, $response->getStatus());
		self::assertSame(
			[
				['handler' => 'jan', 'openCaseCount' => 4, 'displayName' => 'Jan de Vries'],
				['handler' => 'gone', 'openCaseCount' => 1, 'displayName' => 'gone'],
			],
			$response->getData()['handlers']
		);
	}//end testWorkloadReturns200ForCoordinator()

	/**
	 * The workload names the people of the reader's teams only, a free one with 0.
	 *
	 * @spec openspec/specs/werkvoorraad-intelligent-queue/spec.md#requirement-coordinator-workload-endpoint-mvp
	 *
	 * @return void
	 */
	public function testWorkloadKeepsTheMembersOfTheReadersTeams(): void {
		$user = $this->mockUser('pieter');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->with('pieter')->willReturn(true);
		$this->teams->method('teamsOf')->willReturn([['groupId' => 'team-a', 'name' => 'Team A']]);
		$this->groupManager->method('displayNamesInGroup')->with('team-a')->willReturn(
			['anouk' => 'Anouk Bakker', 'pieter' => 'Pieter Jansen', 'tim' => 'Tim Mulder']
		);
		$this->workQueueService->method('computeWorkload')->willReturn([
			['handler' => 'beheerder', 'openCaseCount' => 10],
			['handler' => 'anouk', 'openCaseCount' => 9],
			['handler' => 'pieter', 'openCaseCount' => 7],
		]);
		$this->userManager->method('getDisplayName')->willReturnArgument(0);

		$handlers = $this->controller->workload()->getData()['handlers'];

		self::assertSame(['anouk', 'pieter', 'tim'], array_column($handlers, 'handler'));
		self::assertSame([9, 7, 0], array_column($handlers, 'openCaseCount'));
	}//end testWorkloadKeepsTheMembersOfTheReadersTeams()

	/**
	 * @return void
	 */
	public function testWorkloadReturns500WhenServiceThrows(): void {
		$user = $this->mockUser('admin-alice');
		$this->userSession->method('getUser')->willReturn($user);
		$this->groupManager->method('isAdmin')->willReturn(true);
		$this->workQueueService->method('computeWorkload')->willThrowException(new \RuntimeException('boom'));

		$response = $this->controller->workload();

		self::assertSame(500, $response->getStatus());
	}//end testWorkloadReturns500WhenServiceThrows()
}//end class
