<?php

/**
 * TriageWakeJob writes as the background service account.
 *
 * Cron has no user, so OpenRegister refused the wake amend as Anonymous and a
 * slept triage item never came back to the queue. These tests drive the REAL
 * job, TriageSleep and IntakeLog into a register that refuses a write from
 * nobody.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use DateTime;
use OCA\Dossiq\BackgroundJob\TriageWakeJob;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Intake\TriageSleep;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Tests\Support\AnonymousRefusingRegister;
use OCA\Dossiq\Tests\Support\MakesBackgroundServiceAccount;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCP\AppFramework\Utility\ITimeFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The wake amend runs as the configured account, or not at all.
 *
 * @covers \OCA\Dossiq\BackgroundJob\TriageWakeJob
 * @uses \OCA\Dossiq\Service\Intake\TriageSleep
 * @uses \OCA\Dossiq\Service\Email\IntakeLog
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 */
class TriageWakeJobServiceAccountTest extends TestCase {
	use MakesBackgroundServiceAccount;
	use MakesCaseDateNormaliser;

	/**
	 * The mailIntakeEntry schema id.
	 */
	private const SCHEMA = '20';

	/**
	 * The register the job writes to.
	 *
	 * @var AnonymousRefusingRegister
	 */
	private AnonymousRefusingRegister $register;

	/**
	 * Seed one item whose wake date has passed.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = $this->refusingRegister();
		$this->register->seed(
			schema: self::SCHEMA,
			id: 'entry-1',
			row: [
				'outcome' => IntakeLog::OUTCOME_INBOX,
				'subject' => 'Vraag over mijn aanvraag',
				TriageSleep::FIELD_UNTIL => '2026-10-01',
				TriageSleep::FIELD_REASON => 'Wacht op de aanvrager',
				'sleptBy' => 'alice',
			]
		);
	}//end setUp()

	/**
	 * One run wakes the item, as the service account.
	 *
	 * @return void
	 */
	public function testTheRunWritesAsTheServiceAccount(): void {
		$this->runJobOnce(job: $this->job());

		$this->assertWroteAsTheServiceAccount(register: $this->register, schemas: [self::SCHEMA]);
		$row = $this->register->row(schema: self::SCHEMA, id: 'entry-1');
		$this->assertSame('', $row[TriageSleep::FIELD_UNTIL]);
		$this->assertSame('', $row['sleptBy']);
		$this->assertSame('Vraag over mijn aanvraag', $row['subject'], 'The amend wiped a field it does not own.');
	}//end testTheRunWritesAsTheServiceAccount()

	/**
	 * Without an account nothing is attempted and the admins are told.
	 *
	 * @return void
	 */
	public function testWithoutAnAccountTheRunWritesNothing(): void {
		$this->configuredAccount = '';

		$this->runJobOnce(job: $this->job());

		$this->assertSame([], $this->register->writes);
		$this->assertSame([], $this->register->refusals);
		$this->assertGreaterThanOrEqual(1, $this->adminNotices);
		$this->assertSame('2026-10-01', $this->register->row(schema: self::SCHEMA, id: 'entry-1')[TriageSleep::FIELD_UNTIL]);
		$this->assertNull($this->acting);
	}//end testWithoutAnAccountTheRunWritesNothing()

	/**
	 * The real job over the real sleep and log.
	 *
	 * @return TriageWakeJob The job.
	 */
	private function job(): TriageWakeJob {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => match ($key) {
				'register' => '5',
				IntakeLog::SCHEMA_KEY => self::SCHEMA,
				default => $default,
			}
		);

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn (): DateTime => new DateTime('2026-10-07 12:00:00'));

		$log = new IntakeLog(
			settingsService: $settings,
			time: $time,
			logger: new NullLogger(),
			timeline: $this->createMock(CaseTimeline::class),
		);

		return $this->buildWith(
			TriageWakeJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'sleep' => new TriageSleep(log: $log, dates: $this->caseDates(), time: $time),
				'logger' => new NullLogger(),
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}//end job()
}//end class
