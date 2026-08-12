<?php

/**
 * ComplaintController Unit Tests
 *
 * Tests for complaint REST endpoints: list, create, show, status transitions,
 * hearing scheduling, disposition submission, analytics, and authorization.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/complaint-management/tasks.md#task-TASK-CM-06
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\ComplaintAnalyticsController;
use OCA\Procest\Controller\ComplaintCategoryController;
use OCA\Procest\Controller\ComplaintController;
use OCA\Procest\Controller\ComplaintDispositionController;
use OCA\Procest\Service\Complaint\ComplaintAccessGuard;
use OCA\Procest\Service\ComplaintAnalyticsService;
use OCA\Procest\Service\ComplaintService;
use OCA\Procest\Service\DispositionService;
use OCA\Procest\Service\HearingService;
use OCA\Procest\Service\SettingsService;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ComplaintController.
 *
 * @covers \OCA\Procest\Controller\ComplaintController
 * @covers \OCA\Procest\Controller\ComplaintAnalyticsController
 * @covers \OCA\Procest\Controller\ComplaintCategoryController
 * @covers \OCA\Procest\Controller\ComplaintDispositionController
 *
 * @uses \OCA\Procest\Service\Complaint\ComplaintAccessGuard
 */
class ComplaintControllerTest extends TestCase {

	/**
	 * @var ComplaintService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private ComplaintService $complaintService;

	/**
	 * @var HearingService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private HearingService $hearingService;

	/**
	 * @var DispositionService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private DispositionService $dispositionService;

	/**
	 * @var ComplaintAnalyticsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private ComplaintAnalyticsService $analyticsService;

	/**
	 * @var SettingsService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private SettingsService $settingsService;

	/**
	 * @var IUserSession|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUserSession $userSession;

	/**
	 * @var IGroupManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IGroupManager $groupManager;

	/**
	 * @var IRequest|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IRequest $request;

	/**
	 * @var ComplaintController
	 */
	private ComplaintController $controller;

	/**
	 * @var ComplaintAnalyticsController
	 */
	private ComplaintAnalyticsController $analyticsController;

	/**
	 * @var ComplaintCategoryController
	 */
	private ComplaintCategoryController $categoryController;

	/**
	 * @var ComplaintDispositionController
	 */
	private ComplaintDispositionController $dispositionController;

	/**
	 * @var IUser|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IUser $user;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->complaintService = $this->createMock(ComplaintService::class);
		$this->hearingService = $this->createMock(HearingService::class);
		$this->dispositionService = $this->createMock(DispositionService::class);
		$this->analyticsService = $this->createMock(ComplaintAnalyticsService::class);
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->request = $this->createMock(IRequest::class);
		$this->user = $this->createMock(IUser::class);

		$this->user->method('getUID')->willReturn('test-user');
		$this->userSession->method('getUser')->willReturn($this->user);

		// The access guard is a real collaborator over mocked dependencies, not
		// a mock: stubbing it would make the 401 assertions below assert on the
		// stub rather than on the guard's actual session handling.
		$accessGuard = new ComplaintAccessGuard(
			request: $this->request,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
		);

		$this->controller = new ComplaintController(
			appName: 'procest',
			request: $this->request,
			complaintService: $this->complaintService,
			accessGuard: $accessGuard,
		);

		$this->analyticsController = new ComplaintAnalyticsController(
			appName: 'procest',
			request: $this->request,
			analyticsService: $this->analyticsService,
			accessGuard: $accessGuard,
		);

		$this->categoryController = new ComplaintCategoryController(
			appName: 'procest',
			request: $this->request,
			settingsService: $this->settingsService,
			accessGuard: $accessGuard,
		);

		$this->dispositionController = new ComplaintDispositionController(
			appName: 'procest',
			request: $this->request,
			complaintService: $this->complaintService,
			dispositionService: $this->dispositionService,
			settingsService: $this->settingsService,
			accessGuard: $accessGuard,
		);
	}//end setUp()

	/**
	 * index: returns list of complaints.
	 *
	 * @return void
	 */
	public function testIndexReturnsComplaintList(): void {
		$this->request->method('getParam')->willReturn(null);
		$this->complaintService
			->method('listComplaints')
			->willReturn([
				['id' => 'uuid-1', 'onderwerp' => 'Klacht A'],
				['id' => 'uuid-2', 'onderwerp' => 'Klacht B'],
			]);

		$response = $this->controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertSame(2, $data['count']);
		$this->assertCount(2, $data['results']);
	}//end testIndexReturnsComplaintList()

	/**
	 * index: returns 401 when not authenticated.
	 *
	 * @return void
	 */
	public function testIndexReturns401WhenNotAuthenticated(): void {
		$unauthSession = $this->createMock(IUserSession::class);
		$unauthSession->method('getUser')->willReturn(null);

		$controller = new ComplaintController(
			appName: 'procest',
			request: $this->request,
			complaintService: $this->complaintService,
			accessGuard: new ComplaintAccessGuard(
				request: $this->request,
				userSession: $unauthSession,
				groupManager: $this->groupManager,
			),
		);

		$this->request->method('getParam')->willReturn(null);
		$response = $controller->index();
		$this->assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
	}//end testIndexReturns401WhenNotAuthenticated()

	/**
	 * show: returns 404 when complaint not found.
	 *
	 * @return void
	 */
	public function testShowReturns404WhenNotFound(): void {
		$this->complaintService->method('getComplaint')->willReturn(null);

		$response = $this->controller->show('non-existent-uuid');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testShowReturns404WhenNotFound()

	/**
	 * show: returns complaint when found.
	 *
	 * @return void
	 */
	public function testShowReturnsComplaintWhenFound(): void {
		$complaint = ['id' => 'uuid-1', 'onderwerp' => 'Test klacht', 'status' => 'ontvangen'];
		$this->complaintService->method('getComplaint')->willReturn($complaint);

		$response = $this->controller->show('uuid-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('Test klacht', $response->getData()['onderwerp']);
	}//end testShowReturnsComplaintWhenFound()

	/**
	 * create: returns 400 when service throws RuntimeException.
	 *
	 * @return void
	 */
	public function testCreateReturns400WhenServiceThrows(): void {
		$this->complaintService
			->method('createComplaint')
			->willThrowException(new \RuntimeException('Required fields missing: onderwerp'));

		$response = $this->controller->create();
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testCreateReturns400WhenServiceThrows()

	/**
	 * transition: returns 400 for invalid status transition.
	 *
	 * @return void
	 */
	public function testTransitionReturns400ForInvalidTransition(): void {
		$complaint = ['id' => 'uuid-1', 'status' => 'ontvangen', 'behandelaar' => 'test-user'];
		$this->complaintService->method('getComplaint')->willReturn($complaint);
		$this->complaintService
			->method('transitionStatus')
			->willThrowException(new \RuntimeException('Transition not allowed'));

		$response = $this->controller->transition('uuid-1');
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
	}//end testTransitionReturns400ForInvalidTransition()

	/**
	 * deadlineAlerts: returns overdue and warning grouped complaints.
	 *
	 * @return void
	 */
	public function testDeadlineAlertsReturnsGroupedAlerts(): void {
		$this->request->method('getParam')->willReturn(null);
		$this->complaintService
			->method('getDeadlineAlerts')
			->willReturn([
				'overdue' => [['id' => 'uuid-1']],
				'warning' => [],
			]);

		$response = $this->controller->deadlineAlerts();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('overdue', $data);
		$this->assertArrayHasKey('warning', $data);
		$this->assertCount(1, $data['overdue']);
	}//end testDeadlineAlertsReturnsGroupedAlerts()

	/**
	 * analytics: returns analytics data structure.
	 *
	 * @return void
	 */
	public function testAnalyticsReturnsExpectedKeys(): void {
		$this->request->method('getParam')->willReturnMap([
			['dateFrom', null, null],
			['dateTo', null, null],
		]);

		$this->analyticsService->method('getFrequencyByDimension')->willReturn([]);
		$this->analyticsService->method('getMonthlyTrend')->willReturn([]);
		$this->analyticsService->method('getAverageResolutionTime')->willReturn([]);

		$response = $this->analyticsController->analytics();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$data = $response->getData();
		$this->assertArrayHasKey('byCategorie', $data);
		$this->assertArrayHasKey('byAfdeling', $data);
		$this->assertArrayHasKey('byKanaal', $data);
		$this->assertArrayHasKey('monthlyTrend', $data);
		$this->assertArrayHasKey('avgResolution', $data);
	}//end testAnalyticsReturnsExpectedKeys()

	/**
	 * getDisposition: returns 404 when no disposition exists.
	 *
	 * @return void
	 */
	public function testGetDispositionReturns404WhenNotFound(): void {
		$this->dispositionService->method('getDispositionForComplaint')->willReturn(null);

		$response = $this->dispositionController->getDisposition('uuid-1');
		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}//end testGetDispositionReturns404WhenNotFound()

	/**
	 * categories: returns empty result when OpenRegister unavailable.
	 *
	 * @return void
	 */
	public function testCategoriesReturnsEmptyWhenUnavailable(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);

		$response = $this->categoryController->categories();
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData()['results']);
	}//end testCategoriesReturnsEmptyWhenUnavailable()

}//end class
