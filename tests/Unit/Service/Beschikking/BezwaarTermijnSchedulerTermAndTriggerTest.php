<?php

/**
 * Unit tests for BezwaarTermijnScheduler: the term without a working-day
 * calendar, a case type's own term length, and the bezwaar trigger it saves.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\BezwaarTermijnScheduler;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\BezwaarTermijnScheduler
 */
class BezwaarTermijnSchedulerTermAndTriggerTest extends TestCase {

	/**
	 * An object service double that records what it was asked to save, or throws.
	 *
	 * @param Throwable|null $failure What saveObject throws, or null to succeed.
	 *
	 * @return object
	 */
	private static function objectService(?Throwable $failure=null): object {
		return new class($failure) {
			/**
			 * What was saved, in order.
			 *
			 * @var list<array{register: string, schema: string, object: array<string, mixed>}>
			 */
			public array $saved = [];

			/**
			 * The double.
			 *
			 * @param Throwable|null $failure What saveObject throws, or null to succeed.
			 */
			public function __construct(private readonly ?Throwable $failure) {
			}//end __construct()

			/**
			 * Record the save, or throw.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $object   The object.
			 *
			 * @return array<string, mixed>
			 */
			public function saveObject(string $register, string $schema, array $object): array {
				if ($this->failure !== null) {
					throw $this->failure;
				}

				$this->saved[] = ['register' => $register, 'schema' => $schema, 'object' => $object];

				return $object;
			}//end saveObject()
		};
	}//end objectService()

	/**
	 * The scheduler without a calendar, over the given object service and app config.
	 *
	 * @param object|null           $objectService The object service, null when OpenRegister is absent.
	 * @param array<string, string> $config        The app config values.
	 * @param LoggerInterface|null  $logger        The logger, a quiet one when null.
	 *
	 * @return BezwaarTermijnScheduler
	 */
	private function scheduler(?object $objectService, array $config, ?LoggerInterface $logger=null): BezwaarTermijnScheduler {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default=''): string => ($config[$key] ?? $default)
		);

		return new BezwaarTermijnScheduler(
			settingsService: $settings,
			logger: ($logger ?? $this->createMock(originalClassName: LoggerInterface::class)),
		);
	}//end scheduler()

	/**
	 * The trigger settings that let a trigger be saved.
	 *
	 * @return array<string, string>
	 */
	private static function configured(): array {
		return ['register' => 'dossiq', 'bezwaar_trigger_schema' => 'bezwaartrigger'];
	}//end configured()

	/**
	 * Without a calendar the term is six weeks and the reminder one week before it.
	 *
	 * @return void
	 */
	public function testWithoutACalendarTheTermIsSixWeeksAndTheReminderOneWeekEarlier(): void {
		$term = $this->scheduler(objectService: null, config: [])->computeTermijn(bekendmaking: '2026-03-16');

		$this->assertSame(expected: ['endDate' => '2026-04-27', 'herinnering' => '2026-04-20'], actual: $term);
	}//end testWithoutACalendarTheTermIsSixWeeksAndTheReminderOneWeekEarlier()

	/**
	 * A case type's own term length replaces the six weeks.
	 *
	 * @return void
	 */
	public function testACaseTypesOwnTermLengthReplacesTheSixWeeks(): void {
		$term = $this->scheduler(objectService: null, config: [])->computeTermijn(bekendmaking: '2026-03-16', termDays: 28);

		$this->assertSame(expected: ['endDate' => '2026-04-13', 'herinnering' => '2026-04-06'], actual: $term);
	}//end testACaseTypesOwnTermLengthReplacesTheSixWeeks()

	/**
	 * A term length of zero keeps the six weeks.
	 *
	 * @return void
	 */
	public function testAZeroTermLengthKeepsTheSixWeeks(): void {
		$term = $this->scheduler(objectService: null, config: [])->computeTermijn(bekendmaking: '2026-03-16', termDays: 0);

		$this->assertSame(expected: '2026-04-27', actual: $term['endDate']);
	}//end testAZeroTermLengthKeepsTheSixWeeks()

	/**
	 * The trigger is saved with the term, not yet objected, active, and archived the day after the term ends.
	 *
	 * @return void
	 */
	public function testTheTriggerIsSavedWithTheTermAndAnArchiveDateTheDayAfter(): void {
		$objects = self::objectService();

		$this->scheduler(objectService: $objects, config: self::configured())->createBezwaarTrigger(
			decisionId: 'dec-1',
			bekendmaking: '2026-03-16',
			endDate: '2026-04-27',
			herinnering: '2026-04-20'
		);

		$this->assertSame(
			expected: [
				[
					'register' => 'dossiq',
					'schema' => 'bezwaartrigger',
					'object' => [
						'decisionId' => 'dec-1',
						'announcementDate' => '2026-03-16',
						'objectionTermEndDate' => '2026-04-27',
						'reminderDate' => '2026-04-20',
						'objectionReceived' => false,
						'archiveTriggerActive' => true,
						'archiveDate' => '2026-04-28',
					],
				],
			],
			actual: $objects->saved
		);
	}//end testTheTriggerIsSavedWithTheTermAndAnArchiveDateTheDayAfter()

	/**
	 * The archive date runs into the next month.
	 *
	 * @return void
	 */
	public function testTheArchiveDateCrossesAMonthEnd(): void {
		$objects = self::objectService();

		$this->scheduler(objectService: $objects, config: self::configured())->createBezwaarTrigger(
			decisionId: 'dec-2',
			bekendmaking: '2026-01-19',
			endDate: '2026-02-28',
			herinnering: '2026-02-21'
		);

		$this->assertSame(expected: '2026-03-01', actual: $objects->saved[0]['object']['archiveDate']);
	}//end testTheArchiveDateCrossesAMonthEnd()

	/**
	 * Without OpenRegister nothing is saved and no setting is read.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterNoTriggerIsSaved(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->expects(self::never())->method('getConfigValue');

		$scheduler = new BezwaarTermijnScheduler(
			settingsService: $settings,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
		$scheduler->createBezwaarTrigger(decisionId: 'dec-1', bekendmaking: '2026-03-16', endDate: '2026-04-27', herinnering: '2026-04-20');

		$this->addToAssertionCount(count: 1);
	}//end testWithoutOpenRegisterNoTriggerIsSaved()

	/**
	 * The settings that leave no place to save a trigger.
	 *
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function unconfigured(): array {
		return [
			'no register' => [['bezwaar_trigger_schema' => 'bezwaartrigger']],
			'no trigger schema' => [['register' => 'dossiq']],
			'neither' => [[]],
		];
	}//end unconfigured()

	/**
	 * Without a register or a trigger schema nothing is saved.
	 *
	 * @param array<string, string> $config The app config values.
	 *
	 * @return void
	 *
	 * @dataProvider unconfigured
	 */
	public function testWithoutARegisterOrTriggerSchemaNoTriggerIsSaved(array $config): void {
		$objects = self::objectService();

		$this->scheduler(objectService: $objects, config: $config)->createBezwaarTrigger(
			decisionId: 'dec-1',
			bekendmaking: '2026-03-16',
			endDate: '2026-04-27',
			herinnering: '2026-04-20'
		);

		$this->assertSame(expected: [], actual: $objects->saved);
	}//end testWithoutARegisterOrTriggerSchemaNoTriggerIsSaved()

	/**
	 * A save that fails is logged with the decision and never thrown.
	 *
	 * @return void
	 */
	public function testAFailingSaveIsLoggedWithTheDecisionAndNotThrown(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects(self::once())->method('error')->with(
			'BeschikkingService: createBezwaarTrigger failed',
			['exception' => 'register offline', 'decisionId' => 'dec-9'],
		);

		$objects = self::objectService(failure: new RuntimeException(message: 'register offline'));
		$this->scheduler(objectService: $objects, config: self::configured(), logger: $logger)->createBezwaarTrigger(
			decisionId: 'dec-9',
			bekendmaking: '2026-03-16',
			endDate: '2026-04-27',
			herinnering: '2026-04-20'
		);
	}//end testAFailingSaveIsLoggedWithTheDecisionAndNotThrown()
}//end class
