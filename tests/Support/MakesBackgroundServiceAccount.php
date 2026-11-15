<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A real BackgroundServiceAccount over a session a test can read.
 *
 * The account is the production class. Only its seams are doubled: the app
 * config that names the account, the user and group managers that answer for
 * it, and the session whose active user `runAs()` swaps. The register reads
 * that same session, so "who wrote this" is answered by the code under test,
 * not by the test.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use Psr\Log\NullLogger;

/**
 * Builds the background service account for a job test.
 */
trait MakesBackgroundServiceAccount {

	/**
	 * Who the session says is signed in right now.
	 *
	 * @var IUser|null
	 */
	protected ?IUser $acting = null;

	/**
	 * The uid the app config names as the background account.
	 *
	 * @var string
	 */
	protected string $configuredAccount = 'dossiq-achtergrond';

	/**
	 * How many times the admins were told the account is missing.
	 *
	 * @var int
	 */
	protected int $adminNotices = 0;

	/**
	 * The uid signed in right now, or null.
	 *
	 * @return string|null The uid.
	 */
	public function actingUid(): ?string {
		return $this->acting?->getUID();
	}//end actingUid()

	/**
	 * The assertions every moved job must pass after one run.
	 *
	 * @param AnonymousRefusingRegister $register The register the job wrote to.
	 * @param array<int, string>        $schemas  The schemas the run must have written.
	 *
	 * @return void
	 */
	protected function assertWroteAsTheServiceAccount(AnonymousRefusingRegister $register, array $schemas): void {
		$this->assertSame([], $register->refusals, 'OpenRegister refused a write: '.implode(' | ', $register->refusals));
		$this->assertSame([], $register->bypasses, 'A write skipped the permission check: '.implode(' | ', $register->bypasses));
		foreach ($schemas as $schema) {
			$this->assertContains($schema, $register->writtenSchemas(), 'Nothing was written to '.$schema.'.');
		}

		$this->assertSame(['dossiq-achtergrond'], $register->writers(), 'A write landed as someone else.');
		$this->assertNull($this->acting, 'The job left the service account signed in.');
	}//end assertWroteAsTheServiceAccount()

	/**
	 * Build a class from named arguments, passing only the ones it declares.
	 *
	 * So the same test runs against a job from before the account existed:
	 * there it is built without one, runs as nobody, and the register refuses
	 * the write. That refusal is the red the fix turns green.
	 *
	 * @param class-string<T>      $class The class.
	 * @param array<string, mixed> $args  The arguments by parameter name.
	 *
	 * @return T The instance.
	 *
	 * @template T of object
	 */
	protected function buildWith(string $class, array $args): object {
		$constructor = (new \ReflectionClass($class))->getConstructor();
		$known = [];
		foreach (($constructor?->getParameters() ?? []) as $parameter) {
			if (array_key_exists($parameter->getName(), $args) === true) {
				$known[$parameter->getName()] = $args[$parameter->getName()];
			}
		}

		return new $class(...$known);
	}//end buildWith()

	/**
	 * Run a job's protected `run()` once, as cron does.
	 *
	 * @param object $job      The job.
	 * @param mixed  $argument The job argument.
	 *
	 * @return void
	 */
	protected function runJobOnce(object $job, mixed $argument = null): void {
		$run = new \ReflectionMethod($job, 'run');
		$run->invoke($job, $argument);
	}//end runJobOnce()

	/**
	 * A register that refuses a write from nobody, reading this session.
	 *
	 * @return AnonymousRefusingRegister The register.
	 */
	protected function refusingRegister(): AnonymousRefusingRegister {
		return new AnonymousRefusingRegister(actor: fn (): ?string => $this->actingUid());
	}//end refusingRegister()

	/**
	 * The session `runAs()` swaps and the register reads.
	 *
	 * @return IUserSession The session.
	 */
	protected function backgroundSession(): IUserSession {
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->acting);
		$session->method('isLoggedIn')->willReturnCallback(fn (): bool => $this->acting !== null);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->acting = $user;
			}
		);

		return $session;
	}//end backgroundSession()

	/**
	 * A user double.
	 *
	 * @param string $uid     The uid.
	 * @param bool   $enabled Whether the account is enabled.
	 *
	 * @return IUser The user.
	 */
	protected function backgroundUser(string $uid, bool $enabled = true): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn($uid);
		$user->method('isEnabled')->willReturn($enabled);

		return $user;
	}//end backgroundUser()

	/**
	 * The production account class over doubled seams.
	 *
	 * @param IUserSession|null $session The session to swap, the shared one by default.
	 *
	 * @return BackgroundServiceAccount The account.
	 */
	protected function backgroundAccount(?IUserSession $session = null): BackgroundServiceAccount {
		$account = $this->backgroundUser(uid: 'dossiq-achtergrond');

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => $this->configuredAccount);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => ($uid === 'dossiq-achtergrond' ? $account : null)
		);

		$admin = $this->backgroundUser(uid: 'admin');
		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('getUsers')->willReturn([$admin]);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool
				=> ($uid === 'dossiq-achtergrond' && $group === BackgroundServiceAccount::GROUP)
		);
		$groups->method('get')->willReturnCallback(
			static fn (string $gid): ?IGroup => ($gid === 'admin' ? $adminGroup : null)
		);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setObject', 'setDateTime', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->method('notify')->willReturnCallback(
			function (): void {
				$this->adminNotices++;
			}
		);

		return new BackgroundServiceAccount(
			appConfig: $config,
			userManager: $users,
			groupManager: $groups,
			userSession: ($session ?? $this->backgroundSession()),
			logger: new NullLogger(),
			notifications: $notifications,
		);
	}//end backgroundAccount()
}//end class
