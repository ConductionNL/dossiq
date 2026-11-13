<?php

/**
 * Tests for arming the timers of DSO cases whose term already runs.
 *
 * DsoDeadlineJob retires in the same release, so an open DSO case would
 * never be warned or marked overdue without this step.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\ArmDsoDeadlineTimers;
use OCA\Dossiq\Service\Dso\DsoDeadlineActs;
use OCA\Dossiq\Service\Dso\DsoDeadlineTimer;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Open DSO cases synced and counted, overdue ones marked, no OpenRegister skipped.
 */
class ArmDsoDeadlineTimersTest extends TestCase {

	/**
	 * Open cases are searched by the open DSO statuses, synced and counted.
	 *
	 * @return void
	 */
	public function testOpenCasesAreSyncedAndCounted(): void {
		$filters = null;
		$objects = new class ($filters) {
			/**
			 * Build the store.
			 *
			 * @param mixed $filters Where the search filters are recorded.
			 */
			public function __construct(private mixed &$filters) {
			}

			/**
			 * Search by slug, recording the filters.
			 *
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param array<string, mixed> $filters  The filters.
			 *
			 * @return array<int, array<string, mixed>>
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				$this->filters = $filters;
				return [['id' => 'case-1'], ['id' => 'case-2']];
			}
		};

		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? '');

		$timer = $this->createMock(DsoDeadlineTimer::class);
		$timer->method('sync')->willReturnCallback(static fn (array $c): string => $c['id'] === 'case-2' ? DsoDeadlineTimer::DUE : DsoDeadlineTimer::ARMED);
		$acts = $this->createMock(DsoDeadlineActs::class);
		$acts->expects($this->once())->method('markOverdue')->with(['id' => 'case-2']);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('DSO term timer migration: 1 armed, 1 marked overdue, 0 failed.');

		(new ArmDsoDeadlineTimers(settingsService: $settings, timer: $timer, acts: $acts, logger: $this->createMock(LoggerInterface::class)))->run(output: $output);

		$this->assertSame(expected: DsoDeadlineTimer::OPEN_STATUSES, actual: $filters['dsoStatus']);
	}//end testOpenCasesAreSyncedAndCounted()

	/**
	 * Without OpenRegister it warns and syncs nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItSkips(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(false);
		$timer = $this->createMock(DsoDeadlineTimer::class);
		$timer->expects($this->never())->method('sync');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$step = new ArmDsoDeadlineTimers(settingsService: $settings, timer: $timer, acts: $this->createMock(DsoDeadlineActs::class), logger: $this->createMock(LoggerInterface::class));
		$step->run(output: $output);
		$this->assertSame(expected: 'Arm OpenRegister engine timers for running Dossiq DSO terms', actual: $step->getName());
	}//end testWithoutOpenRegisterItSkips()

	/**
	 * A failed read is logged and counts nothing; an unavailable engine counts a failure.
	 *
	 * @return void
	 */
	public function testAFailedReadAndAnUnavailableEngineAreCounted(): void {
		foreach ([true, false] as $readFails) {
			$objects = new class ($readFails) {
				/**
				 * Build the store.
				 *
				 * @param bool $readFails Whether the search throws.
				 */
				public function __construct(private bool $readFails) {
				}

				/**
				 * Search by slug, or throw.
				 *
				 * @param string               $register The register.
				 * @param string               $schema   The schema.
				 * @param array<string, mixed> $filters  The filters.
				 *
				 * @return array<int, array<string, mixed>>
				 */
				public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
					if ($this->readFails === true) {
						throw new \RuntimeException('down');
					}

					return [['id' => 'case-1']];
				}
			};

			$settings = $this->createMock(SettingsService::class);
			$settings->method('isOpenRegisterAvailable')->willReturn(true);
			$settings->method('getObjectService')->willReturn($objects);
			$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ['register' => 'dossiq', 'case_schema' => 'case'][$key] ?? '');
			$timer = $this->createMock(DsoDeadlineTimer::class);
			$timer->method('sync')->willReturn(DsoDeadlineTimer::UNAVAILABLE);
			$logger = $this->createMock(LoggerInterface::class);
			if ($readFails === true) {
				$logger->expects($this->once())->method('error')->with('Dossiq DSO term timer migration: the cases could not be read', ['error' => 'down']);
			}

			$logger->expects($this->once())->method('info')->with('Dossiq DSO term timer migration complete', $readFails === true ? ['armed' => 0, 'overdue' => 0, 'failed' => 0] : ['armed' => 0, 'overdue' => 0, 'failed' => 1]);
			$output = $this->createMock(IOutput::class);
			$output->expects($this->once())->method('info')->with($readFails === true ? 'DSO term timer migration: 0 armed, 0 marked overdue, 0 failed.' : 'DSO term timer migration: 0 armed, 0 marked overdue, 1 failed.');

			(new ArmDsoDeadlineTimers(settingsService: $settings, timer: $timer, acts: $this->createMock(DsoDeadlineActs::class), logger: $logger))->run(output: $output);
		}
	}//end testAFailedReadAndAnUnavailableEngineAreCounted()

	/**
	 * Without a register the step warns with its own name.
	 *
	 * @return void
	 */
	public function testWithoutARegisterItWarnsByName(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getObjectService')->willReturn(new \stdClass());
		$settings->method('getConfigValue')->willReturn('');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with('OpenRegister or the case schema is not available. Skipping DSO term timer migration.');

		(new ArmDsoDeadlineTimers(settingsService: $settings, timer: $this->createMock(DsoDeadlineTimer::class), acts: $this->createMock(DsoDeadlineActs::class), logger: $this->createMock(LoggerInterface::class)))->run(output: $output);
	}//end testWithoutARegisterItWarnsByName()
}//end class
