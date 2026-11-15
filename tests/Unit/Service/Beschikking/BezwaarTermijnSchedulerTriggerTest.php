<?php

/**
 * How the BezwaarTrigger record of a beschikking is scheduled, and how a
 * declared term replaces the six weeks.
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
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;

/**
 * @covers \OCA\Dossiq\Service\Beschikking\BezwaarTermijnScheduler
 */
class BezwaarTermijnSchedulerTriggerTest extends TestCase {
	/**
	 * A settings service with the given object service and config.
	 *
	 * @param object|null           $objectService The object service, or none.
	 * @param array<string, string> $config        The config values.
	 *
	 * @return SettingsService
	 */
	private function settings(?object $objectService, array $config = ['register' => 'dossiq', 'bezwaar_trigger_schema' => 'bezwaarTrigger']): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($config[$key] ?? '')
		);
		return $settings;
	}//end settings()

	/**
	 * An object service that records what it is asked to save.
	 *
	 * @param \Throwable|null $failure Thrown by saveObject when set.
	 *
	 * @return object
	 */
	private function objectService(?\Throwable $failure = null): object {
		return new class($failure) {
			/** @var list<array<string, mixed>> */
			public array $saved = [];

			public function __construct(private ?\Throwable $failure) {
			}

			public function saveObject(array $object, ObjectSaveOptions $options = new ObjectSaveOptions(), bool $_rbac = true, bool $_multitenancy = true): array {
				$register = $options->target->register;
				$schema = $options->target->schema;
				if ($this->failure !== null) {
					throw $this->failure;
				}

				$this->saved[] = ['register' => $register, 'schema' => $schema, 'object' => $object];
				return $object;
			}
		};
	}//end objectService()

	public function testADeclaredTermInDaysReplacesTheSixWeeks(): void {
		$scheduler = new BezwaarTermijnScheduler($this->settings(null), $this->createMock(LoggerInterface::class));

		$this->assertSame(
			['endDate' => '2026-01-31', 'herinnering' => '2026-01-24'],
			$scheduler->computeTermijn('2026-01-01', [], 30)
		);
		$this->assertSame(
			['endDate' => '2026-02-12', 'herinnering' => '2026-02-05'],
			$scheduler->computeTermijn('2026-01-01', [], 0)
		);
	}//end testADeclaredTermInDaysReplacesTheSixWeeks()

	public function testTheTriggerRecordIsSavedWithAnArchiveDateTheDayAfterTheTerm(): void {
		$service = $this->objectService();
		$scheduler = new BezwaarTermijnScheduler($this->settings($service), $this->createMock(LoggerInterface::class));

		$scheduler->createBezwaarTrigger('d-1', '2026-01-30', '2026-03-13', '2026-03-06');

		$this->assertSame(
			[[
				'register' => 'dossiq',
				'schema' => 'bezwaarTrigger',
				'object' => [
					'decisionId' => 'd-1',
					'announcementDate' => '2026-01-30',
					'objectionTermEndDate' => '2026-03-13',
					'reminderDate' => '2026-03-06',
					'objectionReceived' => false,
					'archiveTriggerActive' => true,
					'archiveDate' => '2026-03-14',
				],
			]],
			$service->saved
		);
	}//end testTheTriggerRecordIsSavedWithAnArchiveDateTheDayAfterTheTerm()

	public function testNothingIsSavedWithoutAnObjectService(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$settings = $this->settings(null);
		$settings->expects($this->never())->method('getConfigValue');

		(new BezwaarTermijnScheduler($settings, $logger))->createBezwaarTrigger('d-1', '2026-01-30', '2026-03-13', '2026-03-06');
	}//end testNothingIsSavedWithoutAnObjectService()

	/**
	 * @return array<string, array{0: array<string, string>}>
	 */
	public static function incompleteConfig(): array {
		return [
			'no register' => [['bezwaar_trigger_schema' => 'bezwaarTrigger']],
			'no schema' => [['register' => 'dossiq']],
		];
	}//end incompleteConfig()

	/**
	 * @dataProvider incompleteConfig
	 *
	 * @param array<string, string> $config The config values.
	 */
	public function testNothingIsSavedWhenTheTriggerSchemaIsNotConfigured(array $config): void {
		$service = $this->objectService();

		(new BezwaarTermijnScheduler($this->settings($service, $config), $this->createMock(LoggerInterface::class)))
			->createBezwaarTrigger('d-1', '2026-01-30', '2026-03-13', '2026-03-06');

		$this->assertSame([], $service->saved);
	}//end testNothingIsSavedWhenTheTriggerSchemaIsNotConfigured()

	public function testAFailedSaveIsLoggedNotThrown(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with(
			'BeschikkingService: createBezwaarTrigger failed',
			['exception' => 'register offline', 'decisionId' => 'd-9']
		);
		$scheduler = new BezwaarTermijnScheduler($this->settings($this->objectService(new RuntimeException('register offline'))), $logger);

		$scheduler->createBezwaarTrigger('d-9', '2026-01-30', '2026-03-13', '2026-03-06');
	}//end testAFailedSaveIsLoggedNotThrown()
}//end class
