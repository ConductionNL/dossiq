<?php

/**
 * Unit tests for NeedsInputDispatcher.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Stuf;

use OCA\Dossiq\Service\Stuf\NeedsInputDispatcher;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Service\Stuf\NeedsInputDispatcher
 */
class NeedsInputDispatcherTest extends TestCase {
	private function user(string $uid): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}

	public function testEveryAdminIsNotifiedWithTheEndpoint(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$this->user('alice'), $this->user('bob')]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->expects($this->once())->method('get')->with('admin')->willReturn($group);

		$recipients = [];
		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willReturnCallback(function () use (&$recipients): INotification {
			$notification = $this->createMock(INotification::class);
			$notification->expects($this->once())->method('setApp')->with('dossiq')->willReturnSelf();
			$notification->method('setUser')->willReturnCallback(function (string $user) use (&$recipients, $notification): INotification {
				$recipients[] = $user;
				return $notification;
			});
			$notification->method('setDateTime')->willReturnSelf();
			$notification->expects($this->once())->method('setObject')->with('stuf_needs_input', 'ep-1')->willReturnSelf();
			$notification->expects($this->once())->method('setSubject')->with('certificate_expired', ['endpointId' => 'ep-1'])->willReturnSelf();
			$notification->expects($this->once())->method('setMessage')->with('dossiq_stuf_needs_input', ['endpointId' => 'ep-1', 'days' => 3])->willReturnSelf();
			return $notification;
		});
		$notifications->expects($this->exactly(2))->method('notify');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'StUF needs-input event: {type}',
			['type' => 'certificate_expired', 'event' => 'stuf_needs_input', 'endpointId' => 'ep-1', 'days' => 3]
		);

		(new NeedsInputDispatcher($notifications, $groups, $logger))->dispatch('certificate_expired', ['endpointId' => 'ep-1', 'days' => 3]);

		$this->assertSame(['alice', 'bob'], $recipients);
	}

	public function testWithoutAnEndpointTheTypeIsTheObject(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$this->user('alice')]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn($group);
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setMessage'] as $method) {
			$notification->method($method)->willReturnSelf();
		}
		$notification->expects($this->once())->method('setObject')->with('stuf_needs_input', 'mapping_missing')->willReturnSelf();
		$notification->expects($this->once())->method('setSubject')->with('mapping_missing', ['endpointId' => ''])->willReturnSelf();
		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willReturn($notification);

		(new NeedsInputDispatcher($notifications, $groups, $this->createMock(LoggerInterface::class)))->dispatch('mapping_missing');
	}

	public function testNoAdminGroupNotifiesNobody(): void {
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn(null);
		$notifications = $this->createMock(IManager::class);
		$notifications->expects($this->never())->method('createNotification');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('warning');

		(new NeedsInputDispatcher($notifications, $groups, $logger))->dispatch('x');
	}

	public function testAFailingNotificationIsLoggedNotThrown(): void {
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$this->user('alice')]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->willReturn($group);
		$notifications = $this->createMock(IManager::class);
		$notifications->method('createNotification')->willThrowException(new \RuntimeException('no notifier'));
		$logger = $this->createMock(LoggerInterface::class);
		$messages = [];
		$logger->method('warning')->willReturnCallback(function (string $message, array $context) use (&$messages): void {
			$messages[] = [$message, $context];
		});

		(new NeedsInputDispatcher($notifications, $groups, $logger))->dispatch('x');

		$this->assertSame(['StUF needs-input notification failed: {error}', ['error' => 'no notifier']], $messages[1]);
	}
}
