<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Unit tests for the background service account and its ServiceAccount base.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\ServiceAccount
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\ServiceAccount;

use InvalidArgumentException;
use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccount;
use OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Resolving, refusing, assigning and running as the background account.
 *
 * @covers \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @covers \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 * @uses   \OCA\Dossiq\Service\ServiceAccount\ServiceAccountUnavailableException
 */
class BackgroundServiceAccountTest extends TestCase {

	private IAppConfig&MockObject $appConfig;
	private IUserManager&MockObject $userManager;
	private IGroupManager&MockObject $groupManager;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private INotificationManager&MockObject $notifications;
	private string $configured = 'svc';

	/**
	 * Build the doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appConfig = $this->createMock(IAppConfig::class);
		$this->appConfig->method('getValueString')
			->willReturnCallback(fn (string $app, string $key, string $default) => ($app === 'dossiq' && $key === BackgroundServiceAccount::CONFIG_KEY) ? $this->configured : $default);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->notifications = $this->createMock(INotificationManager::class);
	}//end setUp()

	/**
	 * The account under test.
	 *
	 * @return BackgroundServiceAccount
	 */
	private function account(): BackgroundServiceAccount {
		return new BackgroundServiceAccount(
			appConfig: $this->appConfig,
			userManager: $this->userManager,
			groupManager: $this->groupManager,
			userSession: $this->userSession,
			logger: $this->logger,
			notifications: $this->notifications,
		);
	}//end account()

	/**
	 * A user double.
	 *
	 * @param string $uid     The uid.
	 * @param bool   $enabled Whether it is enabled.
	 *
	 * @return IUser&MockObject
	 */
	private function user(string $uid, bool $enabled = true): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}//end user()

	/**
	 * Make the configured uid an enabled group member.
	 *
	 * @return IUser&MockObject
	 */
	private function usableAccount(): IUser&MockObject {
		$svc = $this->user('svc');
		$this->userManager->method('get')->willReturnMap([['svc', $svc]]);
		$this->groupManager->method('isInGroup')->willReturnMap([['svc', BackgroundServiceAccount::GROUP, true]]);
		return $svc;
	}//end usableAccount()

	/**
	 * The configured uid is trimmed and read under the app's key.
	 *
	 * @return void
	 */
	public function testConfiguredUserIdIsTrimmed(): void {
		$this->configured = '  svc  ';
		$this->assertSame('svc', $this->account()->configuredUserId());
		$this->assertSame('dossiq-background-service', $this->account()->group());
	}//end testConfiguredUserIdIsTrimmed()

	/**
	 * Status reports each reason in turn.
	 *
	 * @return void
	 */
	public function testStatusReportsWhyTheAccountCannotBeUsed(): void {
		$this->configured = '';
		$this->assertSame(['userId' => '', 'usable' => false, 'reason' => ServiceAccount::REASON_UNSET], $this->account()->status());

		$this->configured = 'ghost';
		$this->assertSame(['userId' => 'ghost', 'usable' => false, 'reason' => ServiceAccount::REASON_UNKNOWN], $this->account()->status());
	}//end testStatusReportsWhyTheAccountCannotBeUsed()

	/**
	 * A disabled account is reported disabled, an ungrouped one not-in-group.
	 *
	 * @return void
	 */
	public function testStatusReportsDisabledAndUngrouped(): void {
		$this->userManager->method('get')->willReturnMap([
			['off', $this->user('off', false)],
			['loner', $this->user('loner')],
		]);
		$this->groupManager->method('isInGroup')->willReturn(false);

		$this->configured = 'off';
		$this->assertSame(ServiceAccount::REASON_DISABLED, $this->account()->status()['reason']);

		$this->configured = 'loner';
		$this->assertSame(['userId' => 'loner', 'usable' => false, 'reason' => ServiceAccount::REASON_NOT_A_MEMBER], $this->account()->status());
	}//end testStatusReportsDisabledAndUngrouped()

	/**
	 * A usable account is reported usable and returned by require().
	 *
	 * @return void
	 */
	public function testRequireReturnsTheUsableAccount(): void {
		$svc = $this->usableAccount();
		$this->logger->expects($this->never())->method('error');

		$this->assertSame(['userId' => 'svc', 'usable' => true, 'reason' => null], $this->account()->status());
		$this->assertSame($svc, $this->account()->require());
	}//end testRequireReturnsTheUsableAccount()

	/**
	 * A refusal logs the reason, replaces and sends each admin's notification, and throws.
	 *
	 * @return void
	 */
	public function testRequireRefusesAndNotifiesEveryAdmin(): void {
		$this->configured = '';
		$admins = $this->createMock(IGroup::class);
		$admins->method('getUsers')->willReturn([$this->user('alice'), $this->user('bob')]);
		$this->groupManager->method('get')->willReturnMap([['admin', $admins]]);

		$users = [];
		$subjects = [];
		$notification = $this->createMock(INotification::class);
		$notification->method('setApp')->with('dossiq')->willReturnSelf();
		$notification->method('setUser')->willReturnCallback(function (string $uid) use (&$users, $notification) {
			$users[] = $uid;
			return $notification;
		});
		$notification->method('setObject')->with(BackgroundServiceAccount::NOTIFICATION_OBJECT, BackgroundServiceAccount::CONFIG_KEY)->willReturnSelf();
		$notification->method('setDateTime')->willReturnSelf();
		$notification->method('setSubject')->willReturnCallback(function (string $subject, array $params) use (&$subjects, $notification) {
			$subjects[] = [$subject, $params];
			return $notification;
		});
		$this->notifications->method('createNotification')->willReturn($notification);
		$this->notifications->expects($this->exactly(2))->method('markProcessed')->with($notification);
		$this->notifications->expects($this->exactly(2))->method('notify')->with($notification);
		$this->logger->expects($this->once())->method('error')->with(
			$this->stringContains('no usable background service account'),
			$this->callback(fn (array $ctx) => $ctx['reason'] === 'unset' && $ctx['userId'] === '' && $ctx['group'] === BackgroundServiceAccount::GROUP)
		);

		try {
			$this->account()->require();
			$this->fail('require() should refuse');
		} catch (ServiceAccountUnavailableException $e) {
			$this->assertSame('No usable background service account (unset).', $e->getMessage());
		}

		$this->assertSame(['alice', 'bob'], $users);
		$this->assertSame([Notifier::SUBJECT_BACKGROUND_ACCOUNT_MISSING, ['reason' => 'unset', 'group' => BackgroundServiceAccount::GROUP]], $subjects[0]);
	}//end testRequireRefusesAndNotifiesEveryAdmin()

	/**
	 * A failing notifier is logged and never hides the refusal.
	 *
	 * @return void
	 */
	public function testANotificationFailureStillRefuses(): void {
		$this->configured = 'ghost';
		$admins = $this->createMock(IGroup::class);
		$admins->method('getUsers')->willReturn([$this->user('alice')]);
		$this->groupManager->method('get')->willReturnMap([['admin', $admins]]);
		$this->notifications->method('createNotification')->willThrowException(new RuntimeException('down'));
		$messages = [];
		$this->logger->expects($this->exactly(2))->method('error')->willReturnCallback(function (string $m) use (&$messages) {
			$messages[] = $m;
		});

		$this->expectException(ServiceAccountUnavailableException::class);
		$this->expectExceptionMessage('(unknown)');
		try {
			$this->account()->require();
		} finally {
			$this->assertStringContainsString('could not be told', $messages[1] ?? '');
		}
	}//end testANotificationFailureStillRefuses()

	/**
	 * With no admin group the refusal still throws and notifies nobody.
	 *
	 * @return void
	 */
	public function testNoAdminGroupNotifiesNobody(): void {
		$this->configured = '';
		$this->groupManager->method('get')->willReturn(null);
		$this->notifications->expects($this->never())->method('createNotification');

		$this->expectException(ServiceAccountUnavailableException::class);
		$this->account()->require();
	}//end testNoAdminGroupNotifiesNobody()

	/**
	 * An account that vanishes between the check and the lookup is refused as unknown.
	 *
	 * @return void
	 */
	public function testRequireRefusesAnAccountThatVanishesAsUnknown(): void {
		$this->userManager->method('get')->with('svc')->willReturnOnConsecutiveCalls($this->user('svc'), null);
		$this->groupManager->method('isInGroup')->willReturn(true);
		$this->groupManager->method('get')->with('admin')->willReturn(null);
		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq: no usable background service account, the background jobs write nothing and send nothing',
			$this->callback(fn (array $ctx) => $ctx['userId'] === 'svc' && $ctx['reason'] === ServiceAccount::REASON_UNKNOWN)
		);

		$this->expectException(ServiceAccountUnavailableException::class);
		$this->expectExceptionMessage('No usable background service account (unknown).');
		$this->account()->require();
	}//end testRequireRefusesAnAccountThatVanishesAsUnknown()

	/**
	 * runAs() swaps in the account and restores the previous user, even on a throw.
	 *
	 * @return void
	 */
	public function testRunAsRestoresThePreviousUser(): void {
		$svc = $this->usableAccount();
		$previous = $this->user('alice');
		$this->userSession->method('getUser')->willReturn($previous);
		$set = [];
		$this->userSession->method('setVolatileActiveUser')->willReturnCallback(function (?IUser $u) use (&$set) {
			$set[] = $u;
		});

		$this->assertSame(42, $this->account()->runAs(fn () => 42));
		$this->assertSame([$svc, $previous], $set);

		$set = [];
		try {
			$this->account()->runAs(function () {
				throw new RuntimeException('boom');
			});
			$this->fail('the exception should propagate');
		} catch (RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}
		$this->assertSame([$svc, $previous], $set);
	}//end testRunAsRestoresThePreviousUser()

	/**
	 * runAs() runs nothing and swaps nobody when the account is unusable.
	 *
	 * @return void
	 */
	public function testRunAsRunsNothingWithoutAnAccount(): void {
		$this->configured = '';
		$this->userSession->expects($this->never())->method('setVolatileActiveUser');
		$ran = false;

		$this->expectException(ServiceAccountUnavailableException::class);
		try {
			$this->account()->runAs(function () use (&$ran) {
				$ran = true;
			});
		} finally {
			$this->assertFalse($ran);
		}
	}//end testRunAsRunsNothingWithoutAnAccount()

	/**
	 * runAsWhenAvailable() swallows only the refusal.
	 *
	 * @return void
	 */
	public function testRunAsWhenAvailableSwallowsOnlyTheRefusal(): void {
		$this->configured = '';
		$ran = false;
		$this->account()->runAsWhenAvailable(function () use (&$ran) {
			$ran = true;
		});
		$this->assertFalse($ran);

		$this->configured = 'svc';
		$this->usableAccount();
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('other');
		$this->account()->runAsWhenAvailable(function () {
			throw new RuntimeException('other');
		});
	}//end testRunAsWhenAvailableSwallowsOnlyTheRefusal()

	/**
	 * A signed-in user stays the writer; nobody signed in takes the account.
	 *
	 * @return void
	 */
	public function testRunAsWhenNobodyIsSignedIn(): void {
		$this->configured = '';
		$this->userSession->method('getUser')->willReturnOnConsecutiveCalls($this->user('alice'), null);
		$this->userSession->expects($this->never())->method('setVolatileActiveUser');

		$this->assertSame('mine', $this->account()->runAsWhenNobodyIsSignedIn(fn () => 'mine'));

		$this->expectException(ServiceAccountUnavailableException::class);
		$this->account()->runAsWhenNobodyIsSignedIn(fn () => 'never');
	}//end testRunAsWhenNobodyIsSignedIn()

	/**
	 * assign() refuses an empty, unknown or disabled uid and stores nothing.
	 *
	 * @return void
	 */
	public function testAssignRefusesUnusableAccounts(): void {
		$this->userManager->method('get')->willReturnMap([['off', $this->user('off', false)]]);
		$this->appConfig->expects($this->never())->method('setValueString');

		foreach (['  ' => 'No account "" exists.', 'ghost' => 'No account "ghost" exists.', ' off ' => 'The account "off" is disabled.'] as $uid => $message) {
			try {
				$this->account()->assign((string)$uid);
				$this->fail('assign() should refuse '.$uid);
			} catch (InvalidArgumentException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}
	}//end testAssignRefusesUnusableAccounts()

	/**
	 * assign() creates the group, adds the account, stores the trimmed uid and returns the status.
	 *
	 * @return void
	 */
	public function testAssignJoinsTheGroupAndStoresTheUid(): void {
		$svc = $this->usableAccount();
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->with($svc)->willReturn(false);
		$group->expects($this->once())->method('addUser')->with($svc);
		$this->groupManager->method('get')->willReturn(null);
		$this->groupManager->expects($this->once())->method('createGroup')->with(BackgroundServiceAccount::GROUP)->willReturn($group);
		$this->appConfig->expects($this->once())->method('setValueString')->with('dossiq', BackgroundServiceAccount::CONFIG_KEY, 'svc');

		$this->assertSame(['userId' => 'svc', 'usable' => true, 'reason' => null], $this->account()->assign(' svc '));
	}//end testAssignJoinsTheGroupAndStoresTheUid()

	/**
	 * A member already in the existing group is not added again.
	 *
	 * @return void
	 */
	public function testAssignSkipsAddingAnExistingMember(): void {
		$svc = $this->usableAccount();
		$group = $this->createMock(IGroup::class);
		$group->method('inGroup')->willReturn(true);
		$group->expects($this->never())->method('addUser');
		$this->groupManager->method('get')->willReturn($group);
		$this->groupManager->expects($this->never())->method('createGroup');

		$this->assertTrue($this->account()->assign('svc')['usable']);
	}//end testAssignSkipsAddingAnExistingMember()

	/**
	 * ensureGroup() turns a failed or throwing create into InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testEnsureGroupRefusesWhenTheGroupCannotBeMade(): void {
		$this->groupManager->method('get')->willReturn(null);
		$cause = new RuntimeException('db');
		$this->groupManager->method('createGroup')->willReturnOnConsecutiveCalls(null, $this->throwException($cause));
		$this->logger->expects($this->once())->method('error')->with(
			'Dossiq: could not create a service group',
			$this->callback(fn (array $ctx) => $ctx['group'] === BackgroundServiceAccount::GROUP && $ctx['exception'] === 'db')
		);

		try {
			$this->account()->ensureGroup();
			$this->fail('a null group should refuse');
		} catch (InvalidArgumentException $e) {
			$this->assertSame('The group dossiq-background-service could not be created.', $e->getMessage());
			$this->assertNull($e->getPrevious());
		}

		try {
			$this->account()->ensureGroup();
			$this->fail('a throwing create should refuse');
		} catch (InvalidArgumentException $e) {
			$this->assertSame($cause, $e->getPrevious());
		}
	}//end testEnsureGroupRefusesWhenTheGroupCannotBeMade()
}//end class
