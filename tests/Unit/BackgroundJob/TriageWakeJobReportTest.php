<?php

/**
 * What TriageWakeJob reports after a sweep.
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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A sweep that woke items says how many; a sweep that woke none says nothing.
 *
 * @covers \OCA\Dossiq\BackgroundJob\TriageWakeJob
 * @uses \OCA\Dossiq\Service\Intake\TriageSleep
 * @uses \OCA\Dossiq\Service\Email\IntakeLog
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount
 * @uses \OCA\Dossiq\Service\ServiceAccount\ServiceAccount
 */
class TriageWakeJobReportTest extends TestCase {
	use MakesBackgroundServiceAccount;
	use MakesCaseDateNormaliser;

	/**
	 * The mailIntakeEntry schema id.
	 */
	private const SCHEMA = '20';

	/**
	 * Info lines logged: [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $info = [];

	/**
	 * Seed one slept item.
	 *
	 * @param AnonymousRefusingRegister $register The register.
	 * @param string $id The item id.
	 * @param string $until The day it sleeps until.
	 *
	 * @return void
	 */
	private function sleeping(AnonymousRefusingRegister $register, string $id, string $until): void {
		$register->seed(
			schema: self::SCHEMA,
			id: $id,
			row: [
				'outcome' => IntakeLog::OUTCOME_INBOX,
				'subject' => 'Vraag over mijn aanvraag',
				TriageSleep::FIELD_UNTIL => $until,
				TriageSleep::FIELD_REASON => 'Wacht op de aanvrager',
				'sleptBy' => 'alice',
			]
		);
	}

	/**
	 * The real job, sleep and log over the register, on 7 October 2026.
	 *
	 * @param AnonymousRefusingRegister $register The register.
	 *
	 * @return TriageWakeJob The job.
	 */
	private function job(AnonymousRefusingRegister $register): TriageWakeJob {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
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

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context = []): void {
				$this->info[] = [$message, $context];
			}
		);

		return $this->buildWith(
			TriageWakeJob::class,
			[
				'time' => $this->createMock(ITimeFactory::class),
				'sleep' => new TriageSleep(log: $log, dates: $this->caseDates(), time: $time),
				'logger' => $logger,
				'serviceAccount' => $this->backgroundAccount(),
			]
		);
	}

	/**
	 * Two due items are woken and the count is logged once; a later one sleeps on.
	 *
	 * @return void
	 */
	public function testTheWokenItemsAreCounted(): void {
		$register = $this->refusingRegister();
		$this->sleeping($register, 'entry-1', '2026-10-01');
		$this->sleeping($register, 'entry-2', '2026-10-06');
		$this->sleeping($register, 'entry-3', '2026-12-01');

		$this->runJobOnce(job: $this->job($register));

		$this->assertSame(
			[['Dossiq triage: {count} item(s) returned to the queue', ['count' => 2]]],
			$this->info
		);
		$this->assertSame('2026-12-01', $register->row(schema: self::SCHEMA, id: 'entry-3')[TriageSleep::FIELD_UNTIL]);
	}

	/**
	 * Nothing due: nothing is written and nothing is logged.
	 *
	 * @return void
	 */
	public function testANightWithNothingDueLogsNothing(): void {
		$register = $this->refusingRegister();
		$this->sleeping($register, 'entry-3', '2026-12-01');

		$this->runJobOnce(job: $this->job($register));

		$this->assertSame([], $this->info);
		$this->assertSame([], $register->writes);
		$this->assertNull($this->acting);
	}
}
