<?php

/**
 * DeadlineWarningNotice Unit Tests
 *
 * The notice to the handler about the case: the warning subject while days
 * remain, the overdue subject once they do not, the days in its parameters;
 * and a notice the manager will not send logged with the case.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Notification;

use OCA\Dossiq\Service\Notification\DeadlineWarningNotice;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Unit tests for DeadlineWarningNotice.
 *
 * @covers \OCA\Dossiq\Service\Notification\DeadlineWarningNotice
 */
final class DeadlineWarningNoticeTest extends TestCase {

	/**
	 * What was set on the notification, by setter.
	 *
	 * @var array<string, array<int, mixed>>
	 */
	private array $set = [];

	/**
	 * A manager that hands out a recording notification and expects it sent once.
	 *
	 * @return INotificationManager
	 */
	private function manager(): INotificationManager {
		$notification = $this->createMock(originalClassName: INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnCallback(
				function (mixed ...$args) use ($setter, $notification): INotification {
					$this->set[$setter] = $args;
					return $notification;
				}
			);
		}

		$manager = $this->createMock(originalClassName: INotificationManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->expects($this->once())->method('notify')->with($notification);
		return $manager;
	}//end manager()

	/**
	 * Seven days out, the handler gets the warning subject with the days.
	 *
	 * @return void
	 */
	public function testSevenDaysOutTheHandlerIsWarned(): void {
		$notice = new DeadlineWarningNotice(notificationManager: $this->manager(), logger: $this->createMock(originalClassName: LoggerInterface::class));

		$notice->send(userId: 'j.dejong', warning: ['caseId' => 'case-1', 'daysRemaining' => 7, 'isOverdue' => false]);

		$this->assertSame(expected: ['dossiq'], actual: $this->set['setApp']);
		$this->assertSame(expected: ['j.dejong'], actual: $this->set['setUser']);
		$this->assertSame(expected: ['woo_deadline', 'case-1'], actual: $this->set['setObject']);
		$this->assertSame(expected: ['woo_deadline_warning', ['caseId' => 'case-1', 'daysRemaining' => 7]], actual: $this->set['setSubject']);
	}//end testSevenDaysOutTheHandlerIsWarned()

	/**
	 * Past the deadline, the subject is the overdue one and the days are negative.
	 *
	 * @return void
	 */
	public function testPastTheDeadlineTheNoticeIsOverdue(): void {
		$notice = new DeadlineWarningNotice(notificationManager: $this->manager(), logger: $this->createMock(originalClassName: LoggerInterface::class));

		$notice->send(userId: 'j.dejong', warning: ['caseId' => 'case-1', 'daysRemaining' => -3, 'isOverdue' => true]);

		$this->assertSame(expected: ['woo_deadline_overdue', ['caseId' => 'case-1', 'daysRemaining' => -3]], actual: $this->set['setSubject']);
	}//end testPastTheDeadlineTheNoticeIsOverdue()

	/**
	 * A notice the manager will not send is logged with the case, and nothing is thrown.
	 *
	 * @return void
	 */
	public function testANoticeThatCannotBeSentIsLogged(): void {
		$manager = $this->createMock(originalClassName: INotificationManager::class);
		$manager->method('createNotification')->willThrowException(new RuntimeException('no notifier'));
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'Failed to send WOO deadline notification: no notifier',
			['app' => 'dossiq', 'caseId' => 'case-1']
		);

		(new DeadlineWarningNotice(notificationManager: $manager, logger: $logger))->send(
			userId: 'j.dejong',
			warning: ['caseId' => 'case-1', 'daysRemaining' => 7, 'isOverdue' => false]
		);
	}//end testANoticeThatCannotBeSentIsLogged()
}//end class
