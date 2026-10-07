<?php

/**
 * AdviceDeadlineJob writes as the background service account.
 *
 * Cron has no user, so OpenRegister refused the expiry of an advice request as
 * Anonymous and the request stayed open past its deadline. These tests drive
 * the REAL job, the REAL AdviceService, AdviceRepository and AdviceNotifier
 * into a register that refuses a write from nobody and records who wrote.
 *
 * Mocked, because none of them writes to OpenRegister: the delegation
 * service, the authorization guard (not on the cron path), the date
 * normaliser and Nextcloud's notification manager.
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
use OCA\Dossiq\BackgroundJob\AdviceDeadlineJob;
use OCA\Dossiq\Service\Advice\AdviceAuthorizationGuard;
use OCA\Dossiq\Service\Advice\AdviceNotifier;
use OCA\Dossiq\Service\Advice\AdviceRepository;
use OCA\Dossiq\Service\AdviceDelegationService;
use OCA\Dossiq\Service\AdviceService;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The expiry is stored as the configured account, or nothing happens.
 *
 * @covers \OCA\Dossiq\BackgroundJob\AdviceDeadlineJob
 * @uses \OCA\Dossiq\Service\AdviceService
 * @uses \OCA\Dossiq\Service\Advice\AdviceRepository
 * @uses \OCA\Dossiq\Service\Advice\AdviceNotifier
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 */
class AdviceDeadlineJobServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The register the job writes to.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * How many reminders the adviseurs got.
	 *
	 * @var int
	 */
	private int $reminders = 0;

	/**
	 * Seed one lapsed request and one due a reminder.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = $this->refusingRegister();
		$this->reminders = 0;
		$this->register->seed(
			schema: 'adviesAanvraag',
			id: 'adv-lapsed',
			row: [
				'status' => 'requested',
				'advisor' => 'adviseur1',
				'deadline' => (new DateTimeImmutable('-2 days'))->format('Y-m-d'),
			]
		);
		$this->register->seed(
			schema: 'adviesAanvraag',
			id: 'adv-remind',
			row: [
				'status' => 'requested',
				'advisor' => 'adviseur2',
				'deadline' => (new DateTimeImmutable('today'))->modify('+3 days')->format('Y-m-d'),
			]
		);
	}//end setUp()

	/**
	 * One run expires the lapsed request as the service account.
	 *
	 * @return void
	 */
	public function testTheRunWritesAsTheServiceAccount(): void {
		$this->runJobOnce(job: $this->job());

		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: ['adviesAanvraag']);
		$this->assertSame('expired', $this->register->row(schema: 'adviesAanvraag', id: 'adv-lapsed')['status']);
		$this->assertSame('requested', $this->register->row(schema: 'adviesAanvraag', id: 'adv-remind')['status']);
		$this->assertSame(1, $this->reminders);
	}//end testTheRunWritesAsTheServiceAccount()

	/**
	 * Without an account nothing is read, written or sent.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheRunWritesNothing(): void {
		$this->configuredAccount = '';

		$this->runJobOnce(job: $this->job());

		$this->assertSame([], $this->register->writes);
		$this->assertSame([], $this->register->refusals);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertSame(0, $this->reminders, 'A reminder went out without an account.');
		$this->assertNull($this->acting);
	}//end testWithoutAnAccountTheRunWritesNothing()

	/**
	 * The real job over the real advice service.
	 *
	 * @return AdviceDeadlineJob The job.
	 */
	private function job(): AdviceDeadlineJob {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'advies_aanvraag_schema' => 'adviesAanvraag',
				default => $default,
			}
		);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject', 'setMessage'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->method('notify')->willReturnCallback(
			function (): void {
				$this->reminders++;
			}
		);

		$advice = new AdviceService(
			settingsService: $settings,
			userSession: $this->backgroundSession(),
			logger: new NullLogger(),
			adviceDelegation: $this->createMock(AdviceDelegationService::class),
			repository: new AdviceRepository(settingsService: $settings, logger: new NullLogger()),
			guard: $this->createMock(AdviceAuthorizationGuard::class),
			notifier: new AdviceNotifier(notificationManager: $notifications, logger: new NullLogger()),
			dates: $this->createMock(CaseDateNormaliser::class),
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister', 'dossiq']);

		return $this->buildWith(
			AdviceDeadlineJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'adviceService' => $advice,
				'settingsService' => $settings,
				'appManager' => $apps,
				'logger' => new NullLogger(),
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end job()
}//end class
