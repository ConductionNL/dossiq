<?php

/**
 * Unit tests for the procest -> dossiq per-user preference migration.
 *
 * Nextcloud namespaces `oc_preferences` by app id, so the rename strands every
 * user's stored preference. Nothing errors — each reader supplies a default —
 * so every user silently reverts to the app's defaults.
 *
 * THE ENUMERATION IS THE POINT. `getUsersForUserValue()` looks like the natural
 * way to find affected users, but it takes a VALUE to match: over an open value
 * set it matches nobody, migrates nothing, and reports success. The step
 * therefore walks users with `callForSeenUsers()` and asks each one's
 * `getUserKeys()`, and `testMigratesEveryStoredKeyRegardlessOfItsValue()` is
 * the test that would fail if anyone swapped that back.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use Closure;
use OCA\Dossiq\Repair\MigrateUserPreferences;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserManager;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the per-user walk, the skips, and the failure paths.
 */
final class MigrateUserPreferencesTest extends TestCase {

	/**
	 * The app id this app used before the rename.
	 *
	 * @var string
	 */
	private const OLD = 'procest';

	/**
	 * The app id this app uses now.
	 *
	 * @var string
	 */
	private const NEW = 'dossiq';

	/**
	 * The in-memory preference store: user => app id => key => value.
	 *
	 * @var array<string, array<string, array<string, string>>>
	 */
	private array $store = [];

	/**
	 * User ids whose key enumeration throws.
	 *
	 * @var array<int, string>
	 */
	private array $keysThrowFor = [];

	/**
	 * User ids whose writes throw.
	 *
	 * @var array<int, string>
	 */
	private array $writeThrowsFor = [];

	/**
	 * Reset the store between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$this->keysThrowFor = [];
		$this->writeThrowsFor = [];

	}//end setUp()

	/**
	 * An IConfig mock backed by `$this->store`.
	 *
	 * @return IConfig
	 */
	private function config(): IConfig {
		$config = $this->createMock(IConfig::class);

		$config->method('getUserKeys')
			->willReturnCallback(
				function (string $userId, string $app): array {
					if (in_array($userId, $this->keysThrowFor, strict: true) === true) {
						throw new RuntimeException('unreadable');
					}

					return array_keys(($this->store[$userId][$app] ?? []));
				}
			);

		$config->method('getUserValue')
			->willReturnCallback(
				function (string $userId, string $app, string $key, string $default = ''): string {
					return ($this->store[$userId][$app][$key] ?? $default);
				}
			);

		$config->method('setUserValue')
			->willReturnCallback(
				function (string $userId, string $app, string $key, string $value): void {
					if (in_array($userId, $this->writeThrowsFor, strict: true) === true) {
						throw new RuntimeException('read-only');
					}

					$this->store[$userId][$app][$key] = $value;
				}
			);

		return $config;
	}//end config()

	/**
	 * An IUserManager mock that walks the users present in the store.
	 *
	 * @param array<int, string>|null $userIds Users to walk; defaults to the store's.
	 * @param bool $throw Whether enumeration itself throws.
	 *
	 * @return IUserManager
	 */
	private function userManager(?array $userIds = null, bool $throw = false): IUserManager {
		$userManager = $this->createMock(IUserManager::class);

		if ($throw === true) {
			$userManager->method('callForSeenUsers')
				->willThrowException(new RuntimeException('no user backend'));
			return $userManager;
		}

		$userManager->method('callForSeenUsers')
			->willReturnCallback(
				function (Closure $callback) use ($userIds): void {
					foreach (($userIds ?? array_keys($this->store)) as $userId) {
						$user = $this->createMock(IUser::class);
						$user->method('getUID')->willReturn($userId);
						$callback($user);
					}
				}
			);

		return $userManager;
	}//end userManager()

	/**
	 * Build the step over the in-memory store.
	 *
	 * @param IUserManager|null $userManager An optional user manager.
	 * @param LoggerInterface|null $logger An optional logger to assert against.
	 *
	 * @return MigrateUserPreferences
	 */
	private function step(
		?IUserManager $userManager = null,
		?LoggerInterface $logger = null,
	): MigrateUserPreferences {
		return new MigrateUserPreferences(
			$this->config(),
			($userManager ?? $this->userManager()),
			($logger ?? $this->createMock(LoggerInterface::class))
		);

	}//end step()

	/**
	 * Every user's stored preferences reach the new app id.
	 *
	 * @return void
	 */
	public function testCopiesEveryUsersPreferences(): void {
		$this->store = [
			'alice' => [self::OLD => ['sidebar_open' => '1', 'view' => 'board']],
			'bob' => [self::OLD => ['view' => 'list']],
		];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame('1', $this->store['alice'][self::NEW]['sidebar_open']);
		$this->assertSame('board', $this->store['alice'][self::NEW]['view']);
		$this->assertSame('list', $this->store['bob'][self::NEW]['view']);

	}//end testCopiesEveryUsersPreferences()

	/**
	 * Keys are found by enumeration, not by matching a value.
	 *
	 * This is the regression test for `getUsersForUserValue()`: that call needs
	 * a value to match, so over an open value set it migrates nothing and still
	 * reports success. Three users here hold three DIFFERENT values under the
	 * same key, and all three must move.
	 *
	 * @return void
	 */
	public function testMigratesEveryStoredKeyRegardlessOfItsValue(): void {
		$this->store = [
			'alice' => [self::OLD => ['view' => 'board']],
			'bob' => [self::OLD => ['view' => 'list']],
			'carol' => [self::OLD => ['view' => 'calendar']],
		];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame('board', $this->store['alice'][self::NEW]['view']);
		$this->assertSame('list', $this->store['bob'][self::NEW]['view']);
		$this->assertSame('calendar', $this->store['carol'][self::NEW]['view']);

	}//end testMigratesEveryStoredKeyRegardlessOfItsValue()

	/**
	 * The old rows survive, so a rollback still finds the preferences.
	 *
	 * @return void
	 */
	public function testLeavesTheOldPreferencesIntact(): void {
		$this->store = ['alice' => [self::OLD => ['view' => 'board']]];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame('board', $this->store['alice'][self::OLD]['view']);

	}//end testLeavesTheOldPreferencesIntact()

	/**
	 * A preference the user already set under the new app id wins.
	 *
	 * @return void
	 */
	public function testDoesNotOverwriteAPreferenceAlreadySetUnderTheNewAppId(): void {
		$this->store = [
			'alice' => [
				self::OLD => ['view' => 'oud'],
				self::NEW => ['view' => 'nieuw'],
			],
		];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame('nieuw', $this->store['alice'][self::NEW]['view']);

	}//end testDoesNotOverwriteAPreferenceAlreadySetUnderTheNewAppId()

	/**
	 * A second run is a no-op, so re-running the repair is safe.
	 *
	 * @return void
	 */
	public function testIsIdempotent(): void {
		$this->store = ['alice' => [self::OLD => ['view' => 'board']]];

		$step = $this->step();
		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertSame('board', $this->store['alice'][self::NEW]['view']);

	}//end testIsIdempotent()

	/**
	 * An empty stored preference earns no row under the new app id.
	 *
	 * @return void
	 */
	public function testSkipsPreferencesWithNoStoredValue(): void {
		$this->store = ['alice' => [self::OLD => ['view' => '']]];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertArrayNotHasKey(self::NEW, $this->store['alice']);

	}//end testSkipsPreferencesWithNoStoredValue()

	/**
	 * A fresh install has nothing to migrate and says so.
	 *
	 * @return void
	 */
	public function testReportsNothingToDoWhenNoUserHasStoredPreferences(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with($this->stringContains('nothing to do'));

		$this->step($this->userManager(['alice']))->run($output);

		$this->assertSame([], $this->store);

	}//end testReportsNothingToDoWhenNoUserHasStoredPreferences()

	/**
	 * One unreadable user does not cost the others their preferences.
	 *
	 * @return void
	 */
	public function testOneUnreadableUserDoesNotStopTheWalk(): void {
		$this->store = [
			'alice' => [self::OLD => ['view' => 'board']],
			'bob' => [self::OLD => ['view' => 'list']],
			'carol' => [self::OLD => ['view' => 'calendar']],
		];

		$this->keysThrowFor = ['bob'];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame('board', $this->store['alice'][self::NEW]['view']);
		$this->assertSame('calendar', $this->store['carol'][self::NEW]['view']);
		$this->assertArrayNotHasKey(self::NEW, $this->store['bob']);

	}//end testOneUnreadableUserDoesNotStopTheWalk()

	/**
	 * A failing write is counted and logged, not swallowed.
	 *
	 * @return void
	 */
	public function testLogsAPreferenceItCouldNotMigrate(): void {
		$this->store = ['alice' => [self::OLD => ['view' => 'board']]];
		$this->writeThrowsFor = ['alice'];

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('could not migrate one user preference'),
				$this->arrayHasKey('key')
			);

		$this->step(logger: $logger)->run($this->createMock(IOutput::class));

	}//end testLogsAPreferenceItCouldNotMigrate()

	/**
	 * A broken user backend warns instead of aborting the install.
	 *
	 * The step runs under `<install>`, the only hook that fires on the fresh
	 * install a rename performs. An escaping throw would abort that install and
	 * the app would never enable at all — so failing to enumerate users has to
	 * degrade to a warning.
	 *
	 * @return void
	 */
	public function testUserEnumerationFailureWarnsInsteadOfThrowing(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('warning')
			->with($this->stringContains('user enumeration failed'));
		$output->expects($this->never())->method('info');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('could not enumerate users'));

		$step = $this->step($this->userManager(throw: true), $logger);
		$step->run($output);

	}//end testUserEnumerationFailureWarnsInsteadOfThrowing()

	/**
	 * The step names both app ids, so the repair output is self-explanatory.
	 *
	 * @return void
	 */
	public function testGetNameNamesBothAppIds(): void {
		$name = $this->step()->getName();

		$this->assertStringContainsString(self::OLD, $name);
		$this->assertStringContainsString('Dossiq', $name);

	}//end testGetNameNamesBothAppIds()

}//end class
