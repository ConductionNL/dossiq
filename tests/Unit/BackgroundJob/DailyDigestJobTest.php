<?php

/**
 * The digest arrives when there is something to say, at the chosen hour.
 *
 * The test that matters most here is the one asserting NOTHING happened. A
 * digest that arrives every morning saying nothing trains people to delete it
 * unread, and then the one that matters is deleted with it, so "an empty queue
 * sends nothing" is a behaviour and not an optimisation.
 *
 * The job is driven for real through `run()` rather than tested in pieces: the
 * loop, the hour comparison and the once-a-day guard are all in the job, and
 * a test of the composer alone would leave every one of them unchecked.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
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
 *
 * @spec openspec/changes/one-personal-queue/specs/my-work/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Dossiq\BackgroundJob\DailyDigestJob;
use OCA\Dossiq\Service\Queue\DailyDigestComposer;
use OCA\Dossiq\Service\Queue\DigestDispatcher;
use OCA\Dossiq\Service\Queue\DigestPreferences;
use OCA\Dossiq\Service\Queue\PersonalQueueService;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\BackgroundJob\DailyDigestJob
 * @covers \OCA\Dossiq\Service\Queue\DailyDigestComposer
 * @covers \OCA\Dossiq\Service\Queue\DigestPreferences
 */
class DailyDigestJobTest extends TestCase {
	/**
	 * The per-user preferences this run holds.
	 *
	 * @var array<string, mixed>
	 */
	private array $prefs = [];

	/**
	 * Every digest the dispatcher was handed.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $sent = [];

	/**
	 * Reset the run.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->prefs = [];
		$this->sent = [];
	}

	/**
	 * A user config backed by an array.
	 *
	 * @return IUserConfig The config.
	 */
	private function userConfig(): IUserConfig {
		$config = $this->createMock(IUserConfig::class);
		$config->method('getValueBool')->willReturnCallback(
			fn (string $uid, string $app, string $key, bool $default = false): bool
				=> (bool)($this->prefs[$uid . '|' . $key] ?? $default)
		);
		$config->method('getValueInt')->willReturnCallback(
			fn (string $uid, string $app, string $key, int $default = 0): int
				=> (int)($this->prefs[$uid . '|' . $key] ?? $default)
		);
		$config->method('getValueString')->willReturnCallback(
			fn (string $uid, string $app, string $key, string $default = ''): string
				=> (string)($this->prefs[$uid . '|' . $key] ?? $default)
		);
		$config->method('setValueBool')->willReturnCallback(
			function (string $uid, string $app, string $key, bool $value): bool {
				$this->prefs[$uid . '|' . $key] = $value;

				return true;
			}
		);
		$config->method('setValueInt')->willReturnCallback(
			function (string $uid, string $app, string $key, int $value): bool {
				$this->prefs[$uid . '|' . $key] = $value;

				return true;
			}
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value): bool {
				$this->prefs[$uid . '|' . $key] = $value;

				return true;
			}
		);

		return $config;
	}

	/**
	 * Run the job once for one person whose queue holds these items.
	 *
	 * @param array<int, array<string, mixed>> $items  What is waiting on them.
	 * @param string                           $userId The person.
	 *
	 * @return void
	 */
	private function runJobFor(array $items, string $userId = 'alice'): void {
		$queue = $this->createMock(PersonalQueueService::class);
		$queue->method('forPerson')->willReturn([
			'items' => $items,
			'groups' => [],
			'groupBy' => 'source',
			'hiddenGroups' => [],
			'unavailable' => [],
			'total' => count($items),
		]);

		$dispatcher = $this->createMock(DigestDispatcher::class);
		$dispatcher->method('send')->willReturnCallback(
			function (array $digest): string {
				$this->sent[] = $digest;

				return 'digest-1';
			}
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$users = $this->createMock(IUserManager::class);
		$users->method('callForSeenUsers')->willReturnCallback(
			static function (callable $callback) use ($user): void {
				$callback($user);
			}
		);

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister', 'dossiq']);

		$job = new DailyDigestJob(
			time: $this->createMock(ITimeFactory::class),
			users: $users,
			appManager: $appManager,
			settings: new DigestPreferences(
				userConfig: $this->userConfig(),
				logger: $this->createMock(LoggerInterface::class)
			),
			composer: new DailyDigestComposer(queue: $queue),
			dispatcher: $dispatcher,
			logger: $this->createMock(LoggerInterface::class)
		);

		$run = new \ReflectionMethod(DailyDigestJob::class, 'run');
		$run->invoke($job, null);
	}

	/**
	 * One waiting item, as the queue answers it.
	 *
	 * @param string $id   The item id.
	 * @param string $tier Its urgency tier.
	 *
	 * @return array<string, mixed> The item.
	 */
	private function waiting(string $id, string $tier = 'normal'): array {
		return [
			'id' => $id,
			'title' => 'Waiting ' . $id,
			'source' => 'assigned-cases',
			'tier' => $tier,
		];
	}

	/**
	 * A person with work waiting gets one message naming it.
	 *
	 * @return void
	 */
	public function testAPersonWithWaitingWorkGetsOneDigest(): void {
		$this->prefs['alice|' . DigestPreferences::PREF_HOUR] = (int)(new DateTimeImmutable())->format('G');

		$this->runJobFor([
			$this->waiting('a', 'overdue'),
			$this->waiting('b'),
			$this->waiting('c'),
			$this->waiting('d'),
		]);

		self::assertCount(1, $this->sent);
		self::assertSame(4, $this->sent[0]['waiting']);
		self::assertSame(1, $this->sent[0]['late']);
		self::assertSame('alice', $this->sent[0]['person']);
		self::assertCount(4, $this->sent[0]['named']);
	}

	/**
	 * An empty queue sends nothing at all.
	 *
	 * @return void
	 */
	public function testAnEmptyQueueSendsNothing(): void {
		$this->prefs['alice|' . DigestPreferences::PREF_HOUR] = (int)(new DateTimeImmutable())->format('G');

		$this->runJobFor([]);

		self::assertSame([], $this->sent);
	}

	/**
	 * A person who switched the digest off gets nothing, whatever is waiting.
	 *
	 * @return void
	 */
	public function testAPersonWhoSwitchedItOffGetsNothing(): void {
		$this->prefs['alice|' . DigestPreferences::PREF_HOUR] = (int)(new DateTimeImmutable())->format('G');
		$this->prefs['alice|' . DigestPreferences::PREF_ENABLED] = false;

		$this->runJobFor([$this->waiting('a')]);

		self::assertSame([], $this->sent);
	}

	/**
	 * The digest waits for the hour the reader chose.
	 *
	 * @return void
	 */
	public function testTheDigestWaitsForTheChosenHour(): void {
		$hour = (int)(new DateTimeImmutable())->format('G');
		$this->prefs['alice|' . DigestPreferences::PREF_HOUR] = (($hour + 1) % 24);

		$this->runJobFor([$this->waiting('a')]);

		self::assertSame([], $this->sent, 'A run in the wrong hour sends nothing.');
	}

	/**
	 * A second run in the same hour does not send a second message.
	 *
	 * @return void
	 */
	public function testASecondRunTheSameDaySendsNothingMore(): void {
		$this->prefs['alice|' . DigestPreferences::PREF_HOUR] = (int)(new DateTimeImmutable())->format('G');

		$this->runJobFor([$this->waiting('a')]);
		$this->runJobFor([$this->waiting('a')]);

		self::assertCount(1, $this->sent);
	}

	/**
	 * Work arriving after an empty morning does not trigger a second digest.
	 *
	 * @return void
	 */
	public function testAnEmptyMorningStillMarksTheDay(): void {
		$this->prefs['alice|' . DigestPreferences::PREF_HOUR] = (int)(new DateTimeImmutable())->format('G');

		$this->runJobFor([]);
		$this->runJobFor([$this->waiting('a')]);

		self::assertSame([], $this->sent);
	}

	/**
	 * Without OpenRegister the job does nothing rather than failing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNothingRuns(): void {
		$users = $this->createMock(IUserManager::class);
		$users->expects(self::never())->method('callForSeenUsers');

		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['dossiq']);

		$job = new DailyDigestJob(
			time: $this->createMock(ITimeFactory::class),
			users: $users,
			appManager: $appManager,
			settings: new DigestPreferences(
				userConfig: $this->userConfig(),
				logger: $this->createMock(LoggerInterface::class)
			),
			composer: new DailyDigestComposer(queue: $this->createMock(PersonalQueueService::class)),
			dispatcher: $this->createMock(DigestDispatcher::class),
			logger: $this->createMock(LoggerInterface::class)
		);

		$run = new \ReflectionMethod(DailyDigestJob::class, 'run');
		$run->invoke($job, null);

		self::assertSame([], $this->sent);
	}

	/**
	 * The digest names at most a handful of items, then stops.
	 *
	 * @return void
	 */
	public function testTheDigestNamesAHandfulAndCountsTheRest(): void {
		$this->prefs['alice|' . DigestPreferences::PREF_HOUR] = (int)(new DateTimeImmutable())->format('G');

		$items = [];
		for ($i = 0; $i < 12; $i++) {
			$items[] = $this->waiting('item-' . $i);
		}

		$this->runJobFor($items);

		self::assertSame(12, $this->sent[0]['waiting']);
		self::assertCount(DailyDigestComposer::NAMED_ITEMS, $this->sent[0]['named']);
	}
}//end class
