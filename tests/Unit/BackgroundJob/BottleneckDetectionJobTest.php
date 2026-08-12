<?php

/**
 * BottleneckDetectionJob Unit Tests
 *
 * Tests for the daily background job that flags cases stalled past a
 * milestone deadline and notifies the assigned case worker.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\BackgroundJob;

use OCA\Procest\BackgroundJob\BottleneckDetectionJob;
use OCA\Procest\Service\MilestoneService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for BottleneckDetectionJob.
 *
 * @covers \OCA\Procest\BackgroundJob\BottleneckDetectionJob
 */
class BottleneckDetectionJobTest extends TestCase {

	/**
	 * The mocked time factory.
	 *
	 * @var ITimeFactory|\PHPUnit\Framework\MockObject\MockObject
	 */
	private ITimeFactory $timeFactory;

	/**
	 * The mocked milestone service.
	 *
	 * @var MilestoneService|\PHPUnit\Framework\MockObject\MockObject
	 */
	private MilestoneService $milestoneService;

	/**
	 * The mocked app manager.
	 *
	 * @var IAppManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private IAppManager $appManager;

	/**
	 * The mocked notification manager.
	 *
	 * @var INotificationManager|\PHPUnit\Framework\MockObject\MockObject
	 */
	private INotificationManager $notificationManager;

	/**
	 * The mocked logger.
	 *
	 * @var LoggerInterface|\PHPUnit\Framework\MockObject\MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * The job under test.
	 *
	 * @var BottleneckDetectionJob
	 */
	private BottleneckDetectionJob $job;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->timeFactory = $this->createMock(ITimeFactory::class);
		$this->milestoneService = $this->createMock(MilestoneService::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->notificationManager = $this->createMock(INotificationManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->job = new BottleneckDetectionJob(
			time: $this->timeFactory,
			milestoneService: $this->milestoneService,
			appManager: $this->appManager,
			notificationManager: $this->notificationManager,
			logger: $this->logger,
		);

	}//end setUp()

	/**
	 * Invoke the protected run() method.
	 *
	 * @return void
	 */
	private function invokeRun(): void {
		$ref = new \ReflectionMethod(objectOrMethod: $this->job, method: 'run');
		$ref->setAccessible(accessible: true);
		$ref->invoke($this->job, null);

	}//end invokeRun()

	/**
	 * Test that run() exits early when OpenRegister is not installed.
	 *
	 * @return void
	 */
	public function testRunExitsEarlyWhenOpenRegisterNotInstalled(): void {
		$this->appManager
			->method('getInstalledApps')
			->willReturn(['procest', 'contacts']);

		$this->milestoneService
			->expects($this->never())
			->method('findStalledCases');

		$this->invokeRun();

	}//end testRunExitsEarlyWhenOpenRegisterNotInstalled()

	/**
	 * Test that a stalled case with an assignee produces a notification.
	 *
	 * @return void
	 */
	public function testStalledCaseNotifiesAssignee(): void {
		$this->appManager
			->method('getInstalledApps')
			->willReturn(['openregister', 'procest']);

		$this->milestoneService
			->method('findStalledCases')
			->willReturn(
				[
					[
						'caseId' => 'zaak-1',
						'caseTitle' => 'Omgevingsvergunning Dorpsstraat 1',
						'caseType' => 'ct-1',
						'assignee' => 'behandelaar-a',
						'milestoneIdentifier' => 'inhoudelijke_beoordeling',
						'milestoneLabel' => 'Inhoudelijke beoordeling',
						'deadline' => '2026-03-08',
						'daysOverdue' => 14,
					],
				]
			);

		$notification = $this->createMock(INotification::class);
		// Fluent setters return the notification itself.
		$notification->method($this->anything())->willReturnSelf();

		$this->notificationManager
			->expects($this->once())
			->method('createNotification')
			->willReturn($notification);

		$notification
			->expects($this->once())
			->method('setUser')
			->with('behandelaar-a')
			->willReturnSelf();

		$this->notificationManager
			->expects($this->once())
			->method('notify')
			->with($notification);

		$this->invokeRun();

	}//end testStalledCaseNotifiesAssignee()

	/**
	 * Test that a stalled case without an assignee is skipped (no notify).
	 *
	 * @return void
	 */
	public function testStalledCaseWithoutAssigneeIsSkipped(): void {
		$this->appManager
			->method('getInstalledApps')
			->willReturn(['openregister', 'procest']);

		$this->milestoneService
			->method('findStalledCases')
			->willReturn(
				[
					[
						'caseId' => 'zaak-2',
						'assignee' => '',
						'milestoneLabel' => 'Documenten compleet',
						'daysOverdue' => 3,
					],
				]
			);

		$this->notificationManager
			->expects($this->never())
			->method('notify');

		$this->invokeRun();

	}//end testStalledCaseWithoutAssigneeIsSkipped()

	/**
	 * Test that an empty stalled list sends no notifications but still logs.
	 *
	 * @return void
	 */
	public function testNoStalledCasesSendsNoNotifications(): void {
		$this->appManager
			->method('getInstalledApps')
			->willReturn(['openregister', 'procest']);

		$this->milestoneService
			->method('findStalledCases')
			->willReturn([]);

		$this->notificationManager
			->expects($this->never())
			->method('notify');

		$this->logger
			->expects($this->atLeastOnce())
			->method('info')
			->with($this->stringContains('0 stalled'));

		$this->invokeRun();

	}//end testNoStalledCasesSendsNoNotifications()

}//end class
