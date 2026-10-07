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
use OCA\Dossiq\Service\Lifecycle\CaseJournal;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCA\Dossiq\Tests\Support\RealSchemaValidator;
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
	 * The notifications that went out, as `user:subject`.
	 *
	 * @var array<int, string>
	 */
	private array $notified = [];

	/**
	 * The case type and status of the seeded cases, as uuids the way the case
	 * schema stores them.
	 *
	 * @var string
	 */
	private const CASE_TYPE = '7f0c1e2a-4b5d-4c6e-8f90-a1b2c3d4e5f6';

	/**
	 * A status type uuid.
	 *
	 * @var string
	 */
	private const STATUS = '0a1b2c3d-4e5f-4a6b-8c7d-9e0f1a2b3c4d';

	/**
	 * Seed one overdue omgevingsvergunning, one decided one and one ordinary case.
	 *
	 * Every row is shaped like the real case schema (uuids for caseType and
	 * status, `assignee` for the handler) and is checked against it, so this
	 * file cannot seed a row OpenRegister would refuse. The job used to filter
	 * on `caseType = omgevingsvergunning` and `status in [submitted,
	 * in_handling]` as plain text, which no stored case can match.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = $this->refusingRegister();
		$this->notified = [];
		$overdue = (new DateTimeImmutable('today'))->modify('-10 days')->format('Y-m-d');
		$rows = [
			'zaak-overdue-1' => ['dsoStatus' => 'in_handling', 'permitApplicationRef' => '5d6e7f80-1a2b-4c3d-8e4f-5a6b7c8d9e01'],
			'zaak-decided-1' => ['dsoStatus' => 'granted', 'permitApplicationRef' => '5d6e7f80-1a2b-4c3d-8e4f-5a6b7c8d9e02'],
			'zaak-plain-1' => [],
		];
		$schema = new RealSchemaValidator();
		foreach ($rows as $id => $extra) {
			$row = array_merge(
				[
					'title' => 'Case '.$id,
					'caseType' => self::CASE_TYPE,
					'status' => self::STATUS,
					'startDate' => '2026-01-05',
					'deadlineDate' => $overdue,
					'assignee' => 'behandelaar',
				],
				$extra
			);
			$this->assertSame([], $schema->errors(slug: 'case', payload: $row), 'The seed does not fit the case schema.');
			$this->register->seed(schema: 'case', id: $id, row: $row);
		}
	}//end setUp()

	/**
	 * One run marks the open DSO case overdue as the service account, and tells its handler.
	 *
	 * @return void
	 */
	public function testTheRunWritesAsTheServiceAccount(): void {
		$this->runJobOnce(job: $this->job());

		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: ['case']);
		$this->assertSame(['zaak-overdue-1'], array_values(array_unique(array_column($this->register->writes, 2))));
		$row = $this->register->row(schema: 'case', id: 'zaak-overdue-1');
		$this->assertTrue($row['deadlineOverdue']);
		$this->assertSame(['behandelaar:dso_deadline_overdue'], $this->notified);
	}//end testTheRunWritesAsTheServiceAccount()

	/**
	 * What the job writes fits the real case schema and only names declared fields.
	 *
	 * @return void
	 */
	public function testTheOverdueMarkFitsTheRealCaseSchema(): void {
		$this->runJobOnce(job: $this->job());

		$schema = new RealSchemaValidator();
		$row = $this->register->row(schema: 'case', id: 'zaak-overdue-1');
		$this->assertSame([], $schema->errors(slug: 'case', payload: $row, creating: false));
		$undeclared = array_diff(array_keys($row), array_keys($schema->schemas['case']['properties']), ['id', 'uuid']);
		$this->assertSame([], array_values($undeclared), 'OpenRegister drops an undeclared field in silence.');
		$this->assertSame('dsoDeadlineOverdue', json_decode($row['activity'], true)[0]['type']);
	}//end testTheOverdueMarkFitsTheRealCaseSchema()

	/**
	 * A case already marked is not written again, but its handler still hears.
	 *
	 * @return void
	 */
	public function testAnAlreadyMarkedCaseIsNotWrittenAgain(): void {
		$this->runJobOnce(job: $this->job());
		$this->register->writes = [];

		$this->runJobOnce(job: $this->job());

		$this->assertSame([], $this->register->writes);
		$this->assertCount(2, $this->notified);
	}//end testAnAlreadyMarkedCaseIsNotWrittenAgain()

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
		$this->assertSame([], $this->notified, 'A notification went out without an account.');
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

		$sent = ['user' => '', 'subject' => ''];
		$notification = $this->createMock(INotification::class);
		foreach (['setApp', 'setDateTime', 'setObject'] as $setter) {
			$notification->method($setter)->willReturnSelf();
		}

		$notification->method('setUser')->willReturnCallback(
			function (string $user) use (&$sent, $notification): INotification {
				$sent['user'] = $user;
				return $notification;
			}
		);
		$notification->method('setSubject')->willReturnCallback(
			function (string $subject) use (&$sent, $notification): INotification {
				$sent['subject'] = $subject;
				return $notification;
			}
		);

		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturn($notification);
		$notifications->method('notify')->willReturnCallback(
			function () use (&$sent): void {
				$this->notified[] = $sent['user'].':'.$sent['subject'];
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
				'journal' => new CaseJournal($this->backgroundSession()),
			]
		);
	}//end job()
}//end class
