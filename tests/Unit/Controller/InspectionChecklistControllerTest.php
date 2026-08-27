<?php

/**
 * InspectionChecklistController Unit Tests
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/vth-module/tasks.md#task-4
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\InspectionChecklistController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\InspectionChecklistService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for InspectionChecklistController.
 *
 * @covers \OCA\Dossiq\Controller\InspectionChecklistController
 */
class InspectionChecklistControllerTest extends TestCase {

	/**
	 * @var InspectionChecklistService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private InspectionChecklistService $inspectionChecklistService;

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * @var CaseAccessGuard|\PHPUnit\Framework\MockObject\MockObject
	 */
	private CaseAccessGuard $caseAccessGuard;

	/**
	 * @var InspectionChecklistController
	 */
	private InspectionChecklistController $controller;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->inspectionChecklistService = $this->createMock(InspectionChecklistService::class);
		$this->request = $this->createMock(IRequest::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->caseAccessGuard = $this->createMock(CaseAccessGuard::class);

		$this->controller = new InspectionChecklistController(
			appName: 'dossiq',
			request: $this->request,
			checklistService: $this->inspectionChecklistService,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->logger,
			caseAccessGuard: $this->caseAccessGuard,
		);
	}//end setUp()

	/**
	 * Test that index returns 200 with checklist list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	public function testIndexReturns200(): void {
		$this->request->method('getParam')->willReturn(null);
		$this->inspectionChecklistService
			->method('listChecklists')
			->willReturn([['id' => 'uuid-1', 'name' => 'Test Checklist']]);

		$response = $this->controller->index();

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testIndexReturns200()

	/**
	 * Test that destroy returns 200 on successful deletion.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	public function testDestroyReturns200OnSuccess(): void {
		$this->inspectionChecklistService
			->method('deleteChecklist')
			->willReturn(true);

		$response = $this->controller->destroy(id: 'uuid-1');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testDestroyReturns200OnSuccess()

	/**
	 * Test that destroy returns 500 when deletion fails.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	public function testDestroyReturns500OnFailure(): void {
		$this->inspectionChecklistService
			->method('deleteChecklist')
			->willReturn(false);

		$response = $this->controller->destroy(id: 'uuid-nonexistent');

		$this->assertSame(expected: Http::STATUS_INTERNAL_SERVER_ERROR, actual: $response->getStatus());
	}//end testDestroyReturns500OnFailure()

	/**
	 * Test that getResults returns 200 with a list.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/vth-module/tasks.md#task-4
	 */
	public function testGetResultsReturns200(): void {
		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getUID')->willReturn('inspector1');
		$this->userSession->method('getUser')->willReturn($mockUser);

		// Until the per-case guard landed, "is anyone logged in" WAS this
		// endpoint's entire authorization model, and this case asserted exactly
		// that. It now has to state the relationship it always assumed.
		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(true);

		$this->inspectionChecklistService
			->method('getResultsForCase')
			->willReturn([]);

		$response = $this->controller->getResults(id: 'case-uuid');

		$this->assertSame(expected: Http::STATUS_OK, actual: $response->getStatus());
	}//end testGetResultsReturns200()

	/**
	 * An authenticated caller who does not work on the case is refused, and no
	 * inspection result is read.
	 *
	 * @return void
	 */
	public function testGetResultsRefusesACallerWithoutCaseAccess(): void {
		$mockUser = $this->createMock(IUser::class);
		$mockUser->method('getUID')->willReturn('outsider');
		$this->userSession->method('getUser')->willReturn($mockUser);

		$this->caseAccessGuard->method('hasCaseReadAccess')->willReturn(false);
		$this->inspectionChecklistService
			->expects($this->never())
			->method('getResultsForCase');

		$response = $this->controller->getResults(id: 'someone-elses-case');

		$this->assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
	}//end testGetResultsRefusesACallerWithoutCaseAccess()
}//end class
