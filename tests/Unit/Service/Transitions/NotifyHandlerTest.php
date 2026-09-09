<?php

/**
 * NotifyHandler Unit Tests
 *
 * The handler used to gate its call on `method_exists()` and report success
 * when the method was absent, which it always was. These tests assert the
 * effect instead of the envelope: the notification manager receives a
 * notification, addressed to the right user, under a subject the app's
 * Notifier can actually render.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\Transitions\NotifyHandler;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Transitions\NotifyHandler
 *
 * @uses \OCA\Dossiq\Service\Transitions\ActionResult
 */
class NotifyHandlerTest extends TestCase {
	/**
	 * A notification mock whose fluent setters return itself.
	 *
	 * @return INotification|MockObject
	 */
	private function fluentNotification(): INotification {
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturn($notification);
		}

		return $notification;
	}//end fluentNotification()

	/**
	 * The action hands a notification to the notification manager.
	 *
	 * This is the assertion the old suite did not make. A handler that
	 * dispatches nothing fails here, whatever it returns.
	 *
	 * @return void
	 */
	public function testDispatchesTheNotificationToTheManager(): void {
		$notification = $this->fluentNotification();
		$notification->expects(self::once())->method('setApp')->with(Application::APP_ID);
		$notification->expects(self::once())->method('setUser')->with('alice');
		$notification->expects(self::once())->method('setObject')->with('case', 'c-1');
		$notification->expects(self::once())
			->method('setSubject')
			->with(
				Notifier::SUBJECT_CASE_STATUS_CHANGED,
				['caseId' => 'c-1', 'transitionLabel' => 'Done', 'message' => 'Klaar'],
			);

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->expects(self::once())->method('notify')->with($notification);

		$handler = new NotifyHandler(notificationManager: $manager, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'notify', 'userId' => 'alice', 'message' => 'Klaar'],
			case: ['id' => 'c-1'],
			transitionContext: ['transitionLabel' => 'Done'],
		);

		self::assertTrue($result->succeeded);
		self::assertSame('alice', $result->data['userId']);
	}//end testDispatchesTheNotificationToTheManager()

	/**
	 * Without an explicit userId the case's assignee is notified.
	 *
	 * @return void
	 */
	public function testFallsBackToTheCaseAssignee(): void {
		$notification = $this->fluentNotification();
		$notification->expects(self::once())->method('setUser')->with('bob');

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->expects(self::once())->method('notify');

		$handler = new NotifyHandler(notificationManager: $manager, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'notify'],
			case: ['id' => 'c-1', 'assignee' => 'bob'],
			transitionContext: ['transitionLabel' => 'Approved'],
		);

		self::assertTrue($result->succeeded);
		self::assertSame('bob', $result->data['userId']);
	}//end testFallsBackToTheCaseAssignee()

	/**
	 * No recipient means nothing is dispatched and the action says so.
	 *
	 * @return void
	 */
	public function testFailsWhenThereIsNoRecipient(): void {
		$manager = $this->createMock(IManager::class);
		$manager->expects(self::never())->method('notify');

		$handler = new NotifyHandler(notificationManager: $manager, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'notify'],
			case: ['id' => 'c-1'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('notify_missing_recipient', $result->error);
	}//end testFailsWhenThereIsNoRecipient()

	/**
	 * A case with no id is not notified about.
	 *
	 * @return void
	 */
	public function testFailsWhenTheCaseHasNoId(): void {
		$manager = $this->createMock(IManager::class);
		$manager->expects(self::never())->method('notify');

		$handler = new NotifyHandler(notificationManager: $manager, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'notify', 'userId' => 'alice'],
			case: [],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('notify_missing_case', $result->error);
	}//end testFailsWhenTheCaseHasNoId()

	/**
	 * A manager that throws is reported as a failure, not swallowed.
	 *
	 * @return void
	 */
	public function testAFailedDispatchIsReportedAsAFailure(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($this->fluentNotification());
		$manager->method('notify')->willThrowException(new RuntimeException('no notifier'));

		$handler = new NotifyHandler(notificationManager: $manager, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'notify', 'userId' => 'alice'],
			case: ['id' => 'c-1'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('notify_failed', $result->error);
	}//end testAFailedDispatchIsReportedAsAFailure()
}//end class
