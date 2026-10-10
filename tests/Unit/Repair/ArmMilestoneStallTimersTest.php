<?php

/**
 * Tests for arming the milestone stall timers of the cases already waiting.
 *
 * BottleneckDetectionJob retires in the same release, so a waiting case
 * would never be reported stalled without this step.
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

use OCA\Dossiq\Repair\ArmMilestoneStallTimers;
use OCA\Dossiq\Service\Milestone\MilestoneStallActs;
use OCA\Dossiq\Service\Milestone\MilestoneStallTimer;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Cases synced and counted, stalled ones told, no OpenRegister skipped.
 */
class ArmMilestoneStallTimersTest extends TestCase {

	/**
	 * Every case is read with an explicit limit, synced and counted.
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

		$timer = $this->createMock(MilestoneStallTimer::class);
		$timer->method('sync')->willReturnCallback(static fn (array $c): string => $c['id'] === 'case-2' ? MilestoneStallTimer::DUE : MilestoneStallTimer::ARMED);
		$acts = $this->createMock(MilestoneStallActs::class);
		$acts->expects($this->once())->method('notifyIfStalled')->with(['id' => 'case-2']);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Milestone stall timer migration: 1 armed, 1 stalled and told, 0 failed.');

		(new ArmMilestoneStallTimers(settingsService: $settings, timer: $timer, acts: $acts, logger: $this->createMock(LoggerInterface::class)))->run(output: $output);

		$this->assertSame(expected: ['_limit' => 1000], actual: $filters);
	}//end testOpenCasesAreSyncedAndCounted()

	/**
	 * Without OpenRegister it warns and syncs nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItSkips(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(false);
		$timer = $this->createMock(MilestoneStallTimer::class);
		$timer->expects($this->never())->method('sync');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$step = new ArmMilestoneStallTimers(settingsService: $settings, timer: $timer, acts: $this->createMock(MilestoneStallActs::class), logger: $this->createMock(LoggerInterface::class));
		$step->run(output: $output);
		$this->assertSame(expected: 'Arm OpenRegister engine timers for Dossiq milestone stalls', actual: $step->getName());
	}//end testWithoutOpenRegisterItSkips()
}//end class
