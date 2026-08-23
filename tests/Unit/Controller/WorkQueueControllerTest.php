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
use OCA\Dossiq\Service\WorkQueueService;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
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

		$this->controller = new WorkQueueController(
			$this->request,
			$this->userSession,
			$this->groupManager,
			$this->workQueueService,
			$this->logger,
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
	 * @return void
	 */
	public function testIndexReturnsScopedQueueForAuthenticatedUser(): void {
		$user = $this->mockUser('jan');
		$this->userSession->method('getUser')->willReturn($user);

		$fixture = [['id' => 'case-1', 'itemType' => 'case', 'tier' => 'overdue', 'score' => 1005.0]];
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

		$fixture = [['handler' => 'jan', 'openCaseCount' => 4]];
		$this->workQueueService->expects($this->once())
			->method('computeWorkload')
			->willReturn($fixture);

		$response = $this->controller->workload();

		self::assertSame(200, $response->getStatus());
		self::assertSame($fixture, $response->getData()['handlers']);
	}//end testWorkloadReturns200ForCoordinator()

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
