<?php

/**
 * A StUF needs-input event is logged and every admin is notified.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Stuf
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
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
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Stuf\NeedsInputDispatcher
 */
class NeedsInputDispatcherTest extends TestCase {
	/**
	 * The setter calls per notification, keyed by object id.
	 *
	 * @var array<int, array<string, array<int|string, mixed>>>
	 */
	private array $calls = [];

	/**
	 * The notifications handed to notify(), as their setter calls.
	 *
	 * @var list<array<string, array<int|string, mixed>>>
	 */
	private array $sent = [];

	/**
	 * The warnings logged, as [message, context].
	 *
	 * @var list<array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * A dispatcher over the given admin users.
	 *
	 * @param list<string>|null $admins The admin uids, or null when there is no admin group.
	 * @param bool              $fail   Whether notify() throws.
	 *
	 * @return NeedsInputDispatcher
	 */
	private function dispatcher(?array $admins, bool $fail = false): NeedsInputDispatcher {
		$group = null;
		if ($admins !== null) {
			$users = [];
			foreach ($admins as $uid) {
				$user = $this->createMock(IUser::class);
				$user->method('getUID')->willReturn($uid);
				$users[] = $user;
			}

			$group = $this->createMock(IGroup::class);
			$group->method('getUsers')->willReturn($users);
		}

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);

		$manager = $this->createMock(IManager::class);
		$manager->method('createNotification')->willReturnCallback(
			function (): INotification {
				$notification = $this->createMock(INotification::class);
				foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setMessage'] as $setter) {
					$notification->method($setter)->willReturnCallback(
						function (...$arguments) use ($setter, $notification): INotification {
							$this->calls[spl_object_id($notification)][$setter] = $arguments;
							return $notification;
						}
					);
				}

				return $notification;
			}
		);
		$manager->method('notify')->willReturnCallback(
			function (INotification $notification) use ($fail): void {
				if ($fail === true) {
					throw new RuntimeException('notifications app disabled');
				}

				$calls = ($this->calls[spl_object_id($notification)] ?? []);
				unset($calls['setDateTime']);
				$this->sent[] = $calls;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new NeedsInputDispatcher(notificationManager: $manager, groupManager: $groups, logger: $logger);
	}//end dispatcher()

	/**
	 * The event is logged with its context and every admin gets one notification.
	 *
	 * @return void
	 */
	public function testEveryAdminIsNotified(): void {
		$this->dispatcher(admins: ['root', 'ops'])->dispatch(type: 'stuf_endpoint_unreachable', context: ['endpointId' => '42', 'detail' => 'timeout']);

		$this->assertSame(
			[['StUF needs-input event: {type}', ['type' => 'stuf_endpoint_unreachable', 'event' => 'stuf_needs_input', 'endpointId' => '42', 'detail' => 'timeout']]],
			$this->warnings
		);
		$this->assertCount(2, $this->sent);
		$this->assertSame(
			[
				'setApp' => ['dossiq'],
				'setUser' => ['root'],
				'setObject' => ['stuf_needs_input', '42'],
				'setSubject' => ['stuf_endpoint_unreachable', ['endpointId' => '42']],
				'setMessage' => ['dossiq_stuf_needs_input', ['endpointId' => '42', 'detail' => 'timeout']],
			],
			$this->sent[0]
		);
		$this->assertSame(['ops'], $this->sent[1]['setUser']);
	}//end testEveryAdminIsNotified()

	/**
	 * Without an endpoint the object id is the type and the endpoint parameter is empty.
	 *
	 * @return void
	 */
	public function testWithoutAnEndpointTheTypeIsTheObject(): void {
		$this->dispatcher(admins: ['root'])->dispatch(type: 'stuf_mapping_missing');

		$this->assertSame(['stuf_needs_input', 'stuf_mapping_missing'], $this->sent[0]['setObject']);
		$this->assertSame(['stuf_mapping_missing', ['endpointId' => '']], $this->sent[0]['setSubject']);
		$this->assertSame(['dossiq_stuf_needs_input', []], $this->sent[0]['setMessage']);
	}//end testWithoutAnEndpointTheTypeIsTheObject()

	/**
	 * Without an admin group only the event is logged.
	 *
	 * @return void
	 */
	public function testWithoutAnAdminGroupOnlyTheEventIsLogged(): void {
		$this->dispatcher(admins: null)->dispatch(type: 'stuf_mapping_missing');

		$this->assertSame([], $this->sent);
		$this->assertCount(1, $this->warnings);
	}//end testWithoutAnAdminGroupOnlyTheEventIsLogged()

	/**
	 * A failing notification is logged as a second warning and not thrown.
	 *
	 * @return void
	 */
	public function testAFailingNotificationIsLoggedNotThrown(): void {
		$this->dispatcher(admins: ['root'], fail: true)->dispatch(type: 'stuf_mapping_missing');

		$this->assertSame(
			['StUF needs-input notification failed: {error}', ['error' => 'notifications app disabled']],
			$this->warnings[1]
		);
	}//end testAFailingNotificationIsLoggedNotThrown()
}//end class
