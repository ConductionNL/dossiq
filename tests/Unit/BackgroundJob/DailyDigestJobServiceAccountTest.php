<?php

/**
 * Each digest is composed as its recipient, and only sent as the account.
 *
 * Cron has no user. Composed as nobody, OpenRegister returned nothing and no
 * digest ever went out. Composed as the background account, it would list
 * the titles of cases the recipient cannot open, because the account reads
 * more than any one handler. So the job reads each person's queue AS THAT
 * PERSON and writes the digest row as the account.
 *
 * The register here answers a search with the rows the acting user may read,
 * the way OpenRegister's RBAC does, and refuses a write from nobody. The real
 * job, composer, queue service, assigned-cases source and dispatcher run over
 * it; only ordering, view preferences and the source catalogue are doubled.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Dossiq\BackgroundJob\DailyDigestJob;
use OCA\Dossiq\Service\Notification\NotificationRouting;
use OCA\Dossiq\Service\Queue\DailyDigestComposer;
use OCA\Dossiq\Service\Queue\DigestDispatcher;
use OCA\Dossiq\Service\Queue\DigestPreferences;
use OCA\Dossiq\Service\Queue\PersonalQueueService;
use OCA\Dossiq\Service\Queue\QueueItem;
use OCA\Dossiq\Service\Queue\QueueOrdering;
use OCA\Dossiq\Service\Queue\QueueSourceCatalogue;
use OCA\Dossiq\Service\Queue\QueueViewPreferences;
use OCA\Dossiq\Service\Queue\Source\AssignedCasesSource;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Config\IUserConfig;
use OCP\IL10N;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Two recipients, each digest listing only what that recipient may read.
 *
 * @covers \OCA\Dossiq\BackgroundJob\DailyDigestJob
 * @uses \OCA\Dossiq\Service\Queue\DailyDigestComposer
 * @uses \OCA\Dossiq\Service\Queue\PersonalQueueService
 * @uses \OCA\Dossiq\Service\Queue\DigestDispatcher
 * @uses \OCA\Dossiq\Service\Queue\DigestPreferences
 * @uses \OCA\Dossiq\Service\Queue\Source\AssignedCasesSource
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Service\Queue\QueueItem
 * @uses \OCA\Dossiq\Service\Queue\QueueItemLifecycle
 * @uses \OCA\Dossiq\Service\Queue\Source\RegisterBackedSource
 * @uses \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 */
class DailyDigestJobServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * Who may read which case, as OpenRegister's RBAC would answer it.
	 *
	 * The account reads every case, as a broad role would. `case-confidential`
	 * is assigned to alice and readable only by bob: it must never reach her
	 * digest, whatever composes it.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const READERS = [
		'case-alice' => ['alice', 'dossiq-achtergrond'],
		'case-confidential' => ['bob', 'dossiq-achtergrond'],
		'case-bob' => ['bob', 'dossiq-achtergrond'],
	];

	/**
	 * The register.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * User preferences, keyed `uid|key`.
	 *
	 * @var array<string, mixed>
	 */
	private array $prefs = [];

	/**
	 * Seed three open cases; everyone wants their digest this hour.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$readers = self::READERS;
		$this->register = new class(actor: fn (): ?string => $this->actingUid(), readers: $readers) extends AnonymousRefusingRegister {
			/**
			 * Constructor.
			 *
			 * @param \Closure                           $actor   Names the acting user.
			 * @param array<string, array<int, string>> $readers Who may read each id.
			 */
			public function __construct(private readonly \Closure $actor, private readonly array $readers) {
				parent::__construct(actor: $actor);
			}//end __construct()

			/**
			 * A search answers only the rows the acting user may read.
			 *
			 * @param string               $registerSlug  The register.
			 * @param string               $schemaSlug    The schema.
			 * @param array<string, mixed> $filters       The filters.
			 * @param bool                 $_rbac         Whether to check permissions.
			 * @param bool                 $_multitenancy Whether to scope to the organisation.
			 *
			 * @return array<int, array<string, mixed>> The readable rows.
			 */
			public function searchObjectsBySlug(
				string $registerSlug,
				string $schemaSlug,
				array $filters = [],
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$who = ($this->actor)();
				$rows = parent::searchObjectsBySlug($registerSlug, $schemaSlug, $filters, $_rbac, $_multitenancy);

				return array_values(
					array_filter(
						$rows,
						fn (array $row): bool => in_array($who, ($this->readers[$row['id']] ?? []), true)
					)
				);
			}//end searchObjectsBySlug()
		};

		$this->prefs = [];
		$hour = (int)(new DateTimeImmutable())->format('G');
		foreach (['alice', 'bob'] as $uid) {
			$this->prefs[$uid.'|'.DigestPreferences::PREF_HOUR] = $hour;
		}

		foreach (['case-alice' => 'alice', 'case-confidential' => 'alice', 'case-bob' => 'bob'] as $id => $assignee) {
			$this->register->seed(
				schema: 'case',
				id: $id,
				row: ['title' => 'Title of '.$id, 'assignee' => $assignee, 'isFinalStatus' => false]
			);
		}
	}//end setUp()

	/**
	 * Each recipient's digest names only the cases that recipient may read.
	 *
	 * @return void
	 */
	public function testEachDigestListsOnlyWhatItsRecipientMayRead(): void {
		$this->runJobOnce(job: $this->job());

		$digests = $this->digests();
		$this->assertSame(['alice', 'bob'], array_keys($digests));
		$this->assertSame('Title of case-alice', $digests['alice']['summary']);
		$this->assertSame(1, $digests['alice']['waiting']);
		$this->assertStringNotContainsString('case-confidential', implode(' ', array_column($digests, 'summary')).' '.$digests['alice']['summary']);
		$this->assertSame('Title of case-bob', $digests['bob']['summary']);
	}//end testEachDigestListsOnlyWhatItsRecipientMayRead()

	/**
	 * The digest rows are written as the account, and nobody is left signed in.
	 *
	 * @return void
	 */
	public function testTheDigestIsSentAsTheAccount(): void {
		$this->runJobOnce(job: $this->job());

		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: ['workDigest']);
	}//end testTheDigestIsSentAsTheAccount()

	/**
	 * Without an account nothing is composed or sent, and nobody is impersonated.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountNothingIsSent(): void {
		$this->configuredAccount = '';

		$this->runJobOnce(job: $this->job());

		$this->assertSame([], $this->register->writes);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertNull($this->acting);
	}//end testWithoutAnAccountNothingIsSent()

	/**
	 * The digests written, keyed by person.
	 *
	 * @return array<string, array<string, mixed>> The rows.
	 */
	private function digests(): array {
		$out = [];
		foreach (($this->register->rows['workDigest'] ?? []) as $row) {
			$out[(string)$row['person']] = $row;
		}

		ksort($out);

		return $out;
	}//end digests()

	/**
	 * The real job over the read-scoped register.
	 *
	 * @return DailyDigestJob The job.
	 */
	private function job(): DailyDigestJob {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				default => $default,
			}
		);

		$catalogue = $this->createMock(QueueSourceCatalogue::class);
		$catalogue->method('resolve')->willReturn(
			[
				'sources' => [new AssignedCasesSource(settings: $settings, l10n: $this->createMock(IL10N::class))],
				'unavailable' => [],
			]
		);
		$ordering = $this->createMock(QueueOrdering::class);
		$ordering->method('order')->willReturnCallback(
			static fn (array $items): array => array_map(static fn (QueueItem $item): array => $item->jsonSerialize(), $items)
		);
		$view = $this->createMock(QueueViewPreferences::class);
		$view->method('groupBy')->willReturn('source');
		$view->method('hiddenGroups')->willReturn([]);

		$users = [];
		foreach (['alice', 'bob', 'dossiq-achtergrond'] as $uid) {
			$users[] = $this->backgroundUser(uid: $uid);
		}

		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('callForSeenUsers')->willReturnCallback(
			static function (callable $callback) use ($users): void {
				foreach ($users as $user) {
					$callback($user);
				}
			}
		);
		$userManager->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => (array_values(array_filter($users, static fn (IUser $u): bool => $u->getUID() === $uid))[0] ?? null)
		);

		$routing = $this->createMock(NotificationRouting::class);
		$routing->method('digestEnabledFor')->willReturn(null);
		$routing->method('digestDecidedBy')->willReturn(null);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);

		return new DailyDigestJob(
			time: $this->createMock(ITimeFactory::class),
			users: $userManager,
			appManager: $apps,
			settings: new DigestPreferences(userConfig: $this->userConfig(), routing: $routing, logger: new NullLogger()),
			composer: new DailyDigestComposer(
				queue: new PersonalQueueService(catalogue: $catalogue, ordering: $ordering, preferences: $view, logger: new NullLogger())
			),
			dispatcher: new DigestDispatcher(settings: $settings),
			logger: new NullLogger(),
			serviceAccount: $this->backgroundAccount(),
			userSession: $this->backgroundSession(),
		);
	}//end job()

	/**
	 * A user config backed by an array.
	 *
	 * @return IUserConfig The config.
	 */
	private function userConfig(): IUserConfig {
		$config = $this->createMock(IUserConfig::class);
		$config->method('getValueBool')->willReturnCallback(
			fn (string $uid, string $app, string $key, bool $default = false): bool => (bool)($this->prefs[$uid.'|'.$key] ?? $default)
		);
		$config->method('getValueInt')->willReturnCallback(
			fn (string $uid, string $app, string $key, int $default = 0): int => (int)($this->prefs[$uid.'|'.$key] ?? $default)
		);
		$config->method('getValueString')->willReturnCallback(
			fn (string $uid, string $app, string $key, string $default = ''): string => (string)($this->prefs[$uid.'|'.$key] ?? $default)
		);
		$config->method('setValueString')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value): bool {
				$this->prefs[$uid.'|'.$key] = $value;

				return true;
			}
		);

		return $config;
	}//end userConfig()
}//end class
