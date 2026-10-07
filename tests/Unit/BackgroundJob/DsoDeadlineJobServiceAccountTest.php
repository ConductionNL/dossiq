<?php

/**
 * DsoDeadlineJob writes as the background service account.
 *
 * Cron has no user, so OpenRegister refused the overdue mark as Anonymous: the
 * handler was told the case is overdue, the case never said so, and every
 * later run told them again. These tests drive the REAL job into a register
 * that refuses a write from nobody and records who wrote. Only Nextcloud's
 * notification manager and the app config are doubled.
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
use OCA\Dossiq\BackgroundJob\DsoDeadlineJob;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\NullLogger;

/**
 * The overdue mark is stored as the configured account, or nothing happens.
 *
 * @covers \OCA\Dossiq\BackgroundJob\DsoDeadlineJob
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 */
class DsoDeadlineJobServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;

	/**
	 * The register the job writes to.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * How many notifications the handler got.
	 *
	 * @var int
	 */
	private int $notified = 0;

	/**
	 * Seed one overdue omgevingsvergunning.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = $this->refusingRegister();
		$this->notified = 0;
		$this->register->seed(
			schema: 'case',
			id: 'zaak-overdue-1',
			row: [
				'status' => 'submitted',
				'caseType' => 'omgevingsvergunning',
				'deadlineDate' => (new DateTimeImmutable('today'))->modify('-10 days')->format('Y-m-d'),
				'assigneeUserId' => 'behandelaar',
				'deadlineOverdue' => false,
				'activityLog' => [],
			]
		);
	}//end setUp()

	/**
	 * One run marks the case overdue as the service account.
	 *
	 * @return void
	 */
	public function testTheRunWritesAsTheServiceAccount(): void {
		$this->runJobOnce(job: $this->job());

		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: ['case']);
		$row = $this->register->row(schema: 'case', id: 'zaak-overdue-1');
		$this->assertTrue($row['deadlineOverdue']);
		$this->assertSame('deadline_overdue', $row['activityLog'][0]['action']);
		$this->assertSame(1, $this->notified);
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
		$this->assertSame(0, $this->notified, 'A notification went out without an account.');
		$this->assertNull($this->acting);
	}//end testWithoutAnAccountTheRunWritesNothing()

	/**
	 * The real job over the refusing register.
	 *
	 * @return DsoDeadlineJob The job.
	 */
	private function job(): DsoDeadlineJob {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturnCallback(
			static fn (string $app, string $key, string $default = ''): string => match ($key) {
				'register' => 'dossiq',
				'case_schema' => 'case',
				default => $default,
			}
		);

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			fn (string $id): object => match ($id) {
				'OCA\OpenRegister\Service\ObjectService' => $this->register,
			}
		);

		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->method('notify')->willReturnCallback(
			function (): void {
				$this->notified++;
			}
		);

		return $this->buildWith(
			DsoDeadlineJob::class,
			[
				'timeFactory' => $this->createMock(ITimeFactory::class),
				'appConfig' => $config,
				'container' => $container,
				'notificationManager' => $notifications,
				'logger' => new NullLogger(),
				'workingDays' => new WorkingDayCalculator(),
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end job()
}//end class
