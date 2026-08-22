<?php

/**
 * Unit tests for the procest -> dossiq app-config migration.
 *
 * This step is the reason a lost setting is not a crash. Nextcloud namespaces
 * `oc_appconfig` by app id, so the rename leaves every stored row unreachable —
 * and because every reader supplies a default, the app reverts to its defaults
 * without a single error or log line. These tests therefore assert what the
 * step actually WROTE, not merely that `run()` returned.
 *
 * The IAppConfig double is a mock wired to a real in-memory store rather than
 * one with fixed return values: the step reads the old namespace, reads the new
 * one, then writes, so a stub that always returns the same thing would let a
 * step that never wrote anything pass.
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

use OCA\Dossiq\Repair\MigrateAppConfigKeys;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Covers the copy, the skips, and the throw paths that must not escape.
 */
final class MigrateAppConfigKeysTest extends TestCase {

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
	 * The in-memory app-config store: app id => key => value.
	 *
	 * @var array<string, array<string, string>>
	 */
	private array $store = [];

	/**
	 * Keys whose read throws, to exercise the per-key failure path.
	 *
	 * @var array<int, string>
	 */
	private array $throwOn = [];

	/**
	 * Reset the store between tests.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = [];
		$this->throwOn = [];

	}//end setUp()

	/**
	 * An IAppConfig mock backed by `$this->store`.
	 *
	 * @return IAppConfig
	 */
	private function appConfig(): IAppConfig {
		$appConfig = $this->createMock(IAppConfig::class);

		$appConfig->method('getKeys')
			->willReturnCallback(
				function (string $app): array {
					return array_keys(($this->store[$app] ?? []));
				}
			);

		$appConfig->method('getValueString')
			->willReturnCallback(
				function (string $app, string $key, string $default = ''): string {
					if (in_array($key, $this->throwOn, strict: true) === true) {
						throw new RuntimeException('unreadable');
					}

					return ($this->store[$app][$key] ?? $default);
				}
			);

		$appConfig->method('setValueString')
			->willReturnCallback(
				function (string $app, string $key, string $value): bool {
					$this->store[$app][$key] = $value;
					return true;
				}
			);

		return $appConfig;
	}//end appConfig()

	/**
	 * Build the step over the in-memory store.
	 *
	 * @param LoggerInterface|null $logger An optional logger to assert against.
	 *
	 * @return MigrateAppConfigKeys
	 */
	private function step(?LoggerInterface $logger = null): MigrateAppConfigKeys {
		return new MigrateAppConfigKeys(
			$this->appConfig(),
			($logger ?? $this->createMock(LoggerInterface::class))
		);

	}//end step()

	/**
	 * A stored value reaches the new namespace.
	 *
	 * `register` is the worst case: lose it and the ZGW mapping surface goes
	 * quiet rather than broken, because its readers treat '' as "no register
	 * configured" and return early.
	 *
	 * @return void
	 */
	public function testCopiesStoredValuesToTheNewNamespace(): void {
		$this->store = [
			self::OLD => [
				'register' => 'zaken',
				'ai_enabled' => '1',
				'dwangsom_callback_secret' => 's3cr3t',
			],
		];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame('zaken', $this->store[self::NEW]['register']);
		$this->assertSame('1', $this->store[self::NEW]['ai_enabled']);
		$this->assertSame('s3cr3t', $this->store[self::NEW]['dwangsom_callback_secret']);

	}//end testCopiesStoredValuesToTheNewNamespace()

	/**
	 * The old rows survive, so a rollback still finds its configuration.
	 *
	 * @return void
	 */
	public function testLeavesTheOldNamespaceIntact(): void {
		$this->store = [self::OLD => ['register' => 'zaken']];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame('zaken', $this->store[self::OLD]['register']);

	}//end testLeavesTheOldNamespaceIntact()

	/**
	 * Nextcloud's own bookkeeping keys are never copied.
	 *
	 * `enabled` is the dangerous one: AppManager writes it as type MIXED, and
	 * copying it with setValueString() stores type STRING. The next
	 * `app:enable` then fails with an AppConfigTypeConflictException —
	 * permanently, because the conflict is hit before the app can run anything
	 * that would repair it.
	 *
	 * @return void
	 */
	public function testSkipsNextcloudReservedKeys(): void {
		$this->store = [
			self::OLD => [
				'enabled' => 'yes',
				'installed_version' => '1.2.3',
				'types' => 'filesystem',
				'register' => 'zaken',
			],
		];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertArrayNotHasKey('enabled', ($this->store[self::NEW] ?? []));
		$this->assertArrayNotHasKey('installed_version', ($this->store[self::NEW] ?? []));
		$this->assertArrayNotHasKey('types', ($this->store[self::NEW] ?? []));
		$this->assertSame('zaken', $this->store[self::NEW]['register']);

	}//end testSkipsNextcloudReservedKeys()

	/**
	 * An admin edit made after the rename is never clobbered.
	 *
	 * @return void
	 */
	public function testDoesNotOverwriteAValueAlreadySetUnderTheNewAppId(): void {
		$this->store = [
			self::OLD => ['register' => 'oud'],
			self::NEW => ['register' => 'nieuw'],
		];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame('nieuw', $this->store[self::NEW]['register']);

	}//end testDoesNotOverwriteAValueAlreadySetUnderTheNewAppId()

	/**
	 * A second run is a no-op, so re-running the repair is safe.
	 *
	 * @return void
	 */
	public function testIsIdempotent(): void {
		$this->store = [self::OLD => ['register' => 'zaken']];

		$step = $this->step();
		$step->run($this->createMock(IOutput::class));
		$step->run($this->createMock(IOutput::class));

		$this->assertSame('zaken', $this->store[self::NEW]['register']);

	}//end testIsIdempotent()

	/**
	 * An empty stored value earns no row under the new app id.
	 *
	 * @return void
	 */
	public function testSkipsKeysWithNoStoredValue(): void {
		$this->store = [self::OLD => ['email_imap_host' => '']];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertArrayNotHasKey('email_imap_host', ($this->store[self::NEW] ?? []));

	}//end testSkipsKeysWithNoStoredValue()

	/**
	 * A fresh install has nothing to migrate and says so.
	 *
	 * @return void
	 */
	public function testReportsNothingToDoOnAFreshInstall(): void {
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())
			->method('info')
			->with($this->stringContains('nothing to do'));

		$this->step()->run($output);

		$this->assertSame([], $this->store);

	}//end testReportsNothingToDoOnAFreshInstall()

	/**
	 * One unreadable key does not abort the install.
	 *
	 * The step is registered under `<install>` — the only hook that fires on
	 * the fresh install the rename actually performs — so an escaping throw
	 * would abort the install and the app would never enable at all. Every
	 * route in the app dies with it. Hence: the throwing key is skipped and the
	 * rest still migrate.
	 *
	 * @return void
	 */
	public function testOneUnreadableKeyDoesNotStopTheOthers(): void {
		$this->store = [
			self::OLD => [
				'register' => 'zaken',
				'ai_api_key' => 'boom',
				'ai_enabled' => '1',
			],
		];

		$this->throwOn = ['ai_api_key'];

		$this->step()->run($this->createMock(IOutput::class));

		$this->assertSame('zaken', $this->store[self::NEW]['register']);
		$this->assertSame('1', $this->store[self::NEW]['ai_enabled']);
		$this->assertArrayNotHasKey('ai_api_key', $this->store[self::NEW]);

	}//end testOneUnreadableKeyDoesNotStopTheOthers()

	/**
	 * A failing key is logged rather than swallowed silently.
	 *
	 * A migration that loses a value without saying so is the exact failure
	 * mode this step exists to prevent, so the warning is load-bearing.
	 *
	 * @return void
	 */
	public function testLogsAKeyItCouldNotMigrate(): void {
		$this->store = [self::OLD => ['ai_api_key' => 'boom']];
		$this->throwOn = ['ai_api_key'];

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with(
				$this->stringContains('could not migrate one app config key'),
				$this->arrayHasKey('key')
			);

		$this->step($logger)->run($this->createMock(IOutput::class));

	}//end testLogsAKeyItCouldNotMigrate()

	/**
	 * An unreadable old namespace skips the migration instead of throwing.
	 *
	 * @return void
	 */
	public function testUnreadableOldNamespaceIsLoggedAndSkipped(): void {
		$appConfig = $this->createMock(IAppConfig::class);
		$appConfig->method('getKeys')
			->willThrowException(new RuntimeException('no database'));
		$appConfig->expects($this->never())->method('setValueString');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with($this->stringContains('could not enumerate procest app config keys'));

		$step = new MigrateAppConfigKeys($appConfig, $logger);
		$step->run($this->createMock(IOutput::class));

	}//end testUnreadableOldNamespaceIsLoggedAndSkipped()

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
