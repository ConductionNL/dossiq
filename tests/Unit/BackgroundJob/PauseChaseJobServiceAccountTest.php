<?php

/**
 * PauseChaseJob writes as the background service account.
 *
 * Found live (dossiq#3334): the reminder sweep runs from cron with no user, so
 * OpenRegister refused every write as Anonymous. The letter went out, the
 * count of reminders sent was never stored, and the next run chased again.
 * These tests drive the REAL job, the REAL PauseChaseService and the REAL
 * TermijnService and TermInstanceStore into an object service that refuses
 * an anonymous write the way OpenRegister's PermissionHandler does, and
 * records who wrote what.
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
 * @link https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use DateTimeImmutable;
use OCA\Dossiq\BackgroundJob\PauseChaseJob;
use OCA\Dossiq\Notification\Notifier;
use OCA\Dossiq\Service\AanvullingsverzoekService;
use OCA\Dossiq\Service\Pause\ChaseSchedule;
use OCA\Dossiq\Service\Pause\PauseChaseService;
use OCA\Dossiq\Service\Pause\PauseReason;
use OCA\Dossiq\Service\Pause\PauseReasonReader;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use ReflectionMethod;
use RuntimeException;
use Stringable;

/**
 * The reminder job's writes run as the configured account, or not at all.
 *
 * @covers \OCA\Dossiq\BackgroundJob\PauseChaseJob
 * @covers \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 * @covers \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Service\Pause\PauseChaseService
 * @uses \OCA\Dossiq\Service\TermijnService
 * @uses \OCA\Dossiq\Service\Termijn\TermInstanceStore
 * @uses \OCA\Dossiq\Service\Termijn\TermDefinitions
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 * @uses \OCA\Dossiq\Service\Pause\ChaseSchedule
 * @uses \OCA\Dossiq\Service\Pause\PauseReason
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 * @uses \OCA\Dossiq\Service\Settings\RegisterFragmentMerger
 */
class PauseChaseJobServiceAccountTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The account the admin picked.
	 */
	private const ACCOUNT = 'dossiq-achtergrond';

	/**
	 * Who is signed in right now, as the session would answer.
	 *
	 * @var IUser|null
	 */
	private ?IUser $acting = null;

	/**
	 * The configured uid.
	 *
	 * @var string
	 */
	private string $configured = self::ACCOUNT;

	/**
	 * Every write the object service accepted: [uid, schema, payload].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $writes = [];

	/**
	 * Every write the object service refused.
	 *
	 * @var array<int, string>
	 */
	private array $refusals = [];

	/**
	 * The stored term instances by id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $rows = [];

	/**
	 * How many reminders left.
	 *
	 * @var int
	 */
	private int $sent = 0;

	/**
	 * The notifications the admins got.
	 *
	 * @var array<int, string>
	 */
	private array $adminNotices = [];

	/**
	 * The errors logged.
	 *
	 * @var array<int, string>
	 */
	private array $errors = [];

	/**
	 * Reset the shared state.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->acting = null;
		$this->configured = self::ACCOUNT;
		$this->writes = [];
		$this->refusals = [];
		$this->sent = 0;
		$this->adminNotices = [];
		$this->errors = [];
		$this->rows = [
			't1' => [
				'id' => 't1',
				'case' => 'c1',
				'status' => 'paused',
				'pauzeStartDatum' => (new DateTimeImmutable('-7 days'))->format('Y-m-d'),
				'pauseDeadline' => (new DateTimeImmutable('+3 days'))->format('Y-m-d'),
				'pauseReason' => 'aanvulling-aanvrager',
				'chasesSent' => 0,
			],
		];
	}//end setUp()

	/**
	 * The count is stored as the service account, so a second run sends nothing.
	 *
	 * @return void
	 */
	public function testTheCountIsStoredAsTheServiceAccountSoTheSecondRunSendsNothing(): void {
		$job = $this->job();

		$this->runJob(job: $job);
		$this->runJob(job: $job);

		$this->assertSame(1, $this->sent, 'The second run sent the reminder again.');
		$this->assertSame([], $this->refusals, 'OpenRegister refused a write: '.implode(' | ', $this->refusals));
		$this->assertSame(1, (int)$this->rows['t1']['chasesSent']);

		$writers = array_unique(array_map(static fn (array $write): string => $write[0], $this->writes));
		$this->assertSame([self::ACCOUNT], array_values($writers));

		$schemas = array_map(static fn (array $write): string => $write[1], $this->writes);
		$this->assertContains('7', $schemas, 'The term instance was not written.');
		$this->assertContains('8', $schemas, 'The term event was not written.');

		$this->assertNull($this->acting, 'The job left the service account signed in.');
	}//end testTheCountIsStoredAsTheServiceAccountSoTheSecondRunSendsNothing()

	/**
	 * Without an account the job sends nothing, writes nothing, logs and tells the admins.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheJobSendsNothingWritesNothingAndTellsTheAdmins(): void {
		$this->configured = '';

		$this->runJob(job: $this->job());

		$this->assertSame(0, $this->sent);
		$this->assertSame([], $this->writes);
		$this->assertSame([], $this->refusals);
		$this->assertSame([Notifier::SUBJECT_BACKGROUND_ACCOUNT_MISSING], $this->adminNotices);
		$this->assertNotSame([], array_filter(
			$this->errors,
			static fn (string $error): bool => str_contains($error, 'no usable background service account')
		));
		$this->assertNull($this->acting);
	}//end testWithoutAnAccountTheJobSendsNothingWritesNothingAndTellsTheAdmins()

	/**
	 * A disabled account is no account: the job skips.
	 *
	 * @return void
	 */
	public function testADisabledAccountIsRefused(): void {
		$account = $this->account(enabled: false);
		$service = $this->serviceAccount(account: $account);

		$this->assertSame(
			['userId' => self::ACCOUNT, 'usable' => false, 'reason' => BackgroundServiceAccount::REASON_DISABLED],
			$service->status()
		);

		$this->runJob(job: $this->job(serviceAccount: $service));

		$this->assertSame(0, $this->sent);
		$this->assertSame([], $this->writes);
		$this->assertSame([Notifier::SUBJECT_BACKGROUND_ACCOUNT_MISSING], $this->adminNotices);
	}//end testADisabledAccountIsRefused()

	/**
	 * The previous user is restored even when the operation throws.
	 *
	 * @return void
	 */
	public function testThePreviousUserIsRestoredWhenTheOperationThrows(): void {
		$service = $this->serviceAccount(account: $this->account());

		try {
			$service->runAs(static function (): void {
				throw new RuntimeException('boom');
			});
			$this->fail('The exception was swallowed.');
		} catch (RuntimeException $e) {
			$this->assertSame('boom', $e->getMessage());
		}

		$this->assertNull($this->acting);
	}//end testThePreviousUserIsRestoredWhenTheOperationThrows()

	/**
	 * The waiting facts projected onto the case pass the case schema.
	 *
	 * `waitingSince` is a date-time on the case, and the pause start it comes
	 * from is a date. Copied as is, OpenRegister rejected the whole projection
	 * ("should match format 'date-time' but '2026-09-30' does not"), so the
	 * queue never read who the case was waiting on.
	 *
	 * @return void
	 */
	public function testTheCaseProjectionPassesTheRealCaseSchema(): void {
		$this->runJob(job: $this->job());

		$projections = array_values(array_filter($this->writes, static fn (array $write): bool => $write[1] === '9'));
		$this->assertNotSame([], $projections, 'The waiting facts were not written onto the case.');

		$payload = $projections[0][2];
		unset($payload['id']);
		$this->assertSame([], (new RealSchemaValidator())->errors(slug: 'case', payload: $payload, creating: false));

		$started = (string)$this->rows['t1']['pauzeStartDatum'];
		$this->assertStringStartsWith($started.'T00:00:00', (string)$payload['waitingSince']);
	}//end testTheCaseProjectionPassesTheRealCaseSchema()

	/**
	 * Run the job's protected run() once.
	 *
	 * @param PauseChaseJob $job The job.
	 *
	 * @return void
	 */
	private function runJob(PauseChaseJob $job): void {
		$run = new ReflectionMethod($job, 'run');
		$run->invoke($job, null);
	}//end runJob()

	/**
	 * The real job over the real service and store.
	 *
	 * @param BackgroundServiceAccount|null $serviceAccount The account, or the enabled default.
	 *
	 * @return PauseChaseJob
	 */
	private function job(?BackgroundServiceAccount $serviceAccount = null): PauseChaseJob {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objectService());
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => '5',
				'termijn_instance_schema' => '7',
				'termijn_gebeurtenis_schema' => '8',
				'case_schema' => '9',
				default => $default,
			}
		);

		$notifications = $this->createMock(TermijnNotificationService::class);
		$notifications->method('sendTermijnNotification')->willReturnCallback(
			function (): array {
				$this->sent++;
				return [];
			}
		);

		$reasons = $this->createMock(PauseReasonReader::class);
		$reasons->method('caseTypeForCase')->willReturn(
			['handling' => ['automaticMessages' => [PauseChaseService::TEMPLATE]]]
		);
		$reasons->method('reasonsIn')->willReturn([$this->reason()]);

		$aanvullingen = $this->createMock(AanvullingsverzoekService::class);
		$aanvullingen->method('openFor')->willReturn(['recipient' => 'aanvrager@example.org']);

		$chases = new PauseChaseService(
			termService: new TermijnService(settingsService: $settings, logger: new NullLogger()),
			reasons: $reasons,
			schedule: new ChaseSchedule(calendar: new WorkingDayCalculator(), dates: $this->caseDates()),
			notifications: $notifications,
			timeline: $this->createMock(CaseTimeline::class),
			settings: $settings,
			logger: new NullLogger(),
			aanvullingen: $aanvullingen,
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister', 'dossiq']);

		return new PauseChaseJob(
			time: $this->createMock(ITimeFactory::class),
			chases: $chases,
			appManager: $apps,
			logger: $this->logger(),
			serviceAccount: ($serviceAccount ?? $this->serviceAccount(account: $this->account())),
		);
	}//end job()

	/**
	 * The background service account over doubles of Nextcloud's managers.
	 *
	 * @param IUser $account The account the uid resolves to.
	 *
	 * @return BackgroundServiceAccount
	 */
	private function serviceAccount(IUser $account): BackgroundServiceAccount {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(fn (): string => $this->configured);

		$users = $this->createMock(IUserManager::class);
		$users->method('get')->willReturnCallback(
			static fn (string $uid): ?IUser => ($uid === self::ACCOUNT ? $account : null)
		);

		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('admin');
		$adminGroup = $this->createMock(IGroup::class);
		$adminGroup->method('getUsers')->willReturn([$admin]);

		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool
				=> ($uid === self::ACCOUNT && $group === BackgroundServiceAccount::GROUP)
		);
		$groups->method('get')->willReturnCallback(
			static fn (string $gid): ?IGroup => ($gid === 'admin' ? $adminGroup : null)
		);

		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->acting);
		$session->method('setVolatileActiveUser')->willReturnCallback(
			function (?IUser $user): void {
				$this->acting = $user;
			}
		);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setObject', 'setDateTime'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$subject = '';
		$notification->method('setSubject')->willReturnCallback(
			function (string $key) use ($notification, &$subject): INotification {
				$subject = $key;
				return $notification;
			}
		);

		$manager = $this->createMock(INotificationManager::class);
		$manager->method('createNotification')->willReturn($notification);
		$manager->method('notify')->willReturnCallback(
			function () use (&$subject): void {
				$this->adminNotices[] = $subject;
			}
		);

		return new BackgroundServiceAccount(
			appConfig: $config,
			userManager: $users,
			groupManager: $groups,
			userSession: $session,
			logger: $this->logger(),
			notifications: $manager,
		);
	}//end serviceAccount()

	/**
	 * The account the admin picked.
	 *
	 * @param bool $enabled Whether it may sign in.
	 *
	 * @return IUser
	 */
	private function account(bool $enabled = true): IUser {
		$account = $this->createMock(IUser::class);
		$account->method('getUID')->willReturn(self::ACCOUNT);
		$account->method('isEnabled')->willReturn($enabled);
		return $account;
	}//end account()

	/**
	 * A logger that keeps the errors.
	 *
	 * @return AbstractLogger
	 */
	private function logger(): AbstractLogger {
		$errors = &$this->errors;
		return new class($errors) extends AbstractLogger {
			/**
			 * Constructor.
			 *
			 * @param array<int, string> $errors The shared list.
			 */
			public function __construct(private array &$errors) {
			}

			/**
			 * Keep errors.
			 *
			 * @param mixed             $level   The level.
			 * @param string|Stringable $message The message.
			 * @param array<mixed>      $context The context.
			 *
			 * @return void
			 */
			public function log($level, string|Stringable $message, array $context = []): void {
				if ($level === 'error') {
					$this->errors[] = (string)$message;
				}
			}
		};
	}//end logger()

	/**
	 * An object service that refuses an anonymous write as OpenRegister does.
	 *
	 * @return object
	 */
	private function objectService(): object {
		$test = $this;
		return new class($test) {
			/**
			 * Constructor.
			 *
			 * @param PauseChaseJobServiceAccountTest $test The test holding the state.
			 */
			public function __construct(private PauseChaseJobServiceAccountTest $test) {
			}

			/**
			 * Search: every stored paused instance.
			 *
			 * @param array<string, mixed> $query The query.
			 * @param mixed                ...$rest Scope flags.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjects(array $query = [], mixed ...$rest): array {
				return $this->test->pausedRows();
			}

			/**
			 * Find one row.
			 *
			 * @param string     $id       The id.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return array<string, mixed>|null
			 */
			public function find(string $id, int|string $register = '', int|string $schema = ''): ?array {
				return $this->test->row(id: $id);
			}

			/**
			 * Save, refusing Anonymous.
			 *
			 * @param array<string, mixed> $object   The payload.
			 * @param int|string           $register The register.
			 * @param int|string           $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(array $object, int|string $register = '', int|string $schema = '', ?string $uuid = null): array {
				return $this->test->write(schema: (string)$schema, object: $object);
			}

			/**
			 * Patch, refusing Anonymous.
			 *
			 * @param string               $objectId The id.
			 * @param array<string, mixed> $data     The changes.
			 * @param mixed                ...$rest  Register, schema, flags.
			 *
			 * @return array<string, mixed>
			 */
			public function patchObject(string $objectId, array $data, mixed ...$rest): array {
				return $this->test->write(schema: (string)($rest['schema'] ?? ''), object: array_merge(['id' => $objectId], $data));
			}
		};
	}//end objectService()

	/**
	 * The stored paused instances.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function pausedRows(): array {
		return array_values(array_filter($this->rows, static fn (array $row): bool => $row['status'] === 'paused'));
	}//end pausedRows()

	/**
	 * One stored instance.
	 *
	 * @param string $id The id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function row(string $id): ?array {
		return ($this->rows[$id] ?? null);
	}//end row()

	/**
	 * Accept a write as the acting user, or refuse it as OpenRegister refuses Anonymous.
	 *
	 * @param string               $schema The schema.
	 * @param array<string, mixed> $object The payload.
	 *
	 * @return array<string, mixed>
	 */
	public function write(string $schema, array $object): array {
		if ($this->acting === null) {
			$message = "User 'Anonymous' does not have permission to 'update' objects in schema '".$schema."'";
			$this->refusals[] = $message;
			throw new RuntimeException($message);
		}

		$this->writes[] = [$this->acting->getUID(), $schema, $object];
		if ($schema === '7' && isset($object['id']) === true) {
			$this->rows[(string)$object['id']] = $object;
		}

		return $object;
	}//end write()

	/**
	 * The declared reason these cases run under.
	 *
	 * @return array<string, mixed>
	 */
	private function reason(): array {
		return PauseReason::normalise(
			row: [
				'key' => 'aanvulling-aanvrager',
				'name' => 'Aanvulling gevraagd',
				'category' => 'applicant',
				'legalBasis' => 'Awb 4:5',
				'chaseIntervalDays' => 5,
				'chaseBudget' => 2,
				'countsWorkingDays' => false,
				'chaseText' => 'Wij hebben uw aanvulling nog niet ontvangen.',
				'escalateTo' => 'handler',
			]
		);
	}//end reason()
}//end class
