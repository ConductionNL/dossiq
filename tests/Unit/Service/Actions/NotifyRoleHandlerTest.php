<?php

/**
 * NotifyRoleHandler Unit Tests
 *
 * The handler had no test at all, and its body gated the only call it made on
 * `method_exists($service, 'notifyUser')`. That method has never existed, so
 * the loop ran, called nothing, and returned `succeeded: true`.
 *
 * These tests assert the effect, not the envelope: the notification manager
 * receives one notification per role member, addressed to that member, under a
 * subject key the app's Notifier can actually render. A handler that
 * dispatches nothing fails here whatever it returns.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Actions;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\Actions\NotifyRoleHandler;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Actions\NotifyRoleHandler
 *
 * @uses \OCA\Dossiq\Service\Actions\ActionResult
 */
class NotifyRoleHandlerTest extends TestCase {

	/**
	 * A notification mock whose fluent setters return itself.
	 *
	 * @return INotification The double.
	 */
	private function fluentNotification(): INotification {
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturn($notification);
		}

		return $notification;
	}//end fluentNotification()

	/**
	 * The action hands one notification per role member to the manager.
	 *
	 * This is the assertion that did not exist. The old body looped over the
	 * recipients and dispatched nothing.
	 *
	 * @return void
	 */
	public function testDispatchesOneNotificationPerRoleMember(): void {
		$notified = [];

		$notification = $this->fluentNotification();
		$notification->method('setUser')->willReturnCallback(
			function (string $user) use (&$notified, $notification): INotification {
				$notified[] = $user;

				return $notification;
			}
		);

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->expects(self::exactly(2))->method('notify')->with($notification);

		$handler = new NotifyRoleHandler(notificationManager: $manager, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: [
				'type' => 'notifyRole',
				'roleSlug' => 'behandelaar',
				'messageTemplate' => 'Zaak {{case.title}} wacht op je.',
			],
			case: [
				'id' => 'case-1',
				'title' => 'ZK-2024-001',
				'behandelaarMembers' => ['bob', ['id' => 'carol']],
			],
			transitionContext: [],
		);

		self::assertSame(['bob', 'carol'], $notified);
		self::assertTrue($result->succeeded);
		self::assertSame(2, $result->data['notified']);
		self::assertSame('Zaak ZK-2024-001 wacht op je.', $result->data['message']);
	}//end testDispatchesOneNotificationPerRoleMember()

	/**
	 * The dispatched subject is one the Notifier lists.
	 *
	 * Without this the handler dispatches correctly into a channel that
	 * discards the result: `Notifier::prepare()` refuses an unknown subject
	 * and Nextcloud drops the notification before the recipient sees it. That
	 * is the same silent no-op, moved one layer along.
	 *
	 * {@see \OCA\Dossiq\Tests\Unit\Notification\NotifierTest} asserts the
	 * other half, that the key this test pins actually renders.
	 *
	 * @return void
	 */
	public function testTheSubjectIsOneTheNotifierCanRender(): void {
		$notification = $this->fluentNotification();
		$notification->expects(self::once())->method('setApp')->with(Application::APP_ID);
		$notification->expects(self::once())->method('setObject')->with('case', 'case-1');
		$notification->expects(self::once())
			->method('setSubject')
			->with(
				Notifier::SUBJECT_CASE_ROLE_NOTIFIED,
				['caseId' => 'case-1', 'roleSlug' => 'behandelaar', 'message' => 'Pak op.'],
			);

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->expects(self::once())->method('notify');

		$handler = new NotifyRoleHandler(notificationManager: $manager, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: [
				'type' => 'notifyRole',
				'roleSlug' => 'behandelaar',
				'messageTemplate' => 'Pak op.',
			],
			case: ['id' => 'case-1', 'behandelaar' => 'bob'],
			transitionContext: [],
		);

		self::assertTrue($result->succeeded);
	}//end testTheSubjectIsOneTheNotifierCanRender()

	/**
	 * A dry run previews the recipients and dispatches nothing.
	 *
	 * @return void
	 */
	public function testADryRunDispatchesNothing(): void {
		$manager = $this->createMock(IManager::class);
		$manager->expects(self::never())->method('notify');

		$handler = new NotifyRoleHandler(notificationManager: $manager, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'notifyRole', 'roleSlug' => 'behandelaar', 'messageTemplate' => 'Hoi'],
			case: ['id' => 'case-1', 'behandelaar' => 'bob'],
			transitionContext: ['dryRun' => true],
		);

		self::assertTrue($result->succeeded);
		self::assertSame(['bob'], $result->data['recipients']);
	}//end testADryRunDispatchesNothing()

	/**
	 * A role that resolves to nobody is a failure, not a delivery.
	 *
	 * @return void
	 */
	public function testFailsWhenTheRoleResolvesToNobody(): void {
		$manager = $this->createMock(IManager::class);
		$manager->expects(self::never())->method('notify');

		$handler = new NotifyRoleHandler(notificationManager: $manager, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'notifyRole', 'roleSlug' => 'behandelaar', 'messageTemplate' => 'Hoi'],
			case: ['id' => 'case-1'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('no_recipients', $result->error);
	}//end testFailsWhenTheRoleResolvesToNobody()

	/**
	 * A case with no id is not notified about.
	 *
	 * The notification manager rejects an empty object id, and a notification
	 * that names no case cannot be opened anyway.
	 *
	 * @return void
	 */
	public function testFailsWhenTheCaseHasNoId(): void {
		$manager = $this->createMock(IManager::class);
		$manager->expects(self::never())->method('notify');

		$handler = new NotifyRoleHandler(notificationManager: $manager, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'notifyRole', 'roleSlug' => 'behandelaar', 'messageTemplate' => 'Hoi'],
			case: ['behandelaar' => 'bob'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('missing_case_id', $result->error);
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

		$handler = new NotifyRoleHandler(notificationManager: $manager, logger: new NullLogger());

		$result = $handler->handle(
			actionConfig: ['type' => 'notifyRole', 'roleSlug' => 'behandelaar', 'messageTemplate' => 'Hoi'],
			case: ['id' => 'case-1', 'behandelaar' => 'bob'],
			transitionContext: [],
		);

		self::assertFalse($result->succeeded);
		self::assertSame('notify_role_failed', $result->error);
	}//end testAFailedDispatchIsReportedAsAFailure()
}//end class
