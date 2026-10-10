<?php

/**
 * Tests for arming the timers of bezwaar triggers already running.
 *
 * BezwaarTermijnJob retires in the same release, so a trigger active before
 * the upgrade would never archive its beschikking without this step.
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

use OCA\Dossiq\Repair\ArmBezwaarArchiveTimers;
use OCA\Dossiq\Service\Beschikking\BezwaarArchiveTimer;
use OCA\Dossiq\Service\Beschikking\BezwaarArchiveTrigger;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Unit\Service\FakeObjectService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__.'/../Service/BeschikkingServiceTest.php';

/**
 * Only active triggers synced, due ones handled, OpenRegister absent skipped.
 */
class ArmBezwaarArchiveTimersTest extends TestCase {

	/**
	 * Settings over a fake store.
	 *
	 * @param FakeObjectService $objects The store.
	 * @param bool              $orUp    Whether OpenRegister is available.
	 *
	 * @return SettingsService
	 */
	private function settings(FakeObjectService $objects, bool $orUp = true): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($orUp);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'bezwaar_trigger_schema' => 'bezwaarTrigger',
				default => '',
			}
		);
		return $settings;
	}//end settings()

	/**
	 * Active triggers are synced and counted; a due one is handled; an inactive one is not read.
	 *
	 * @return void
	 */
	public function testActiveTriggersAreSyncedAndDueOnesHandled(): void {
		$objects = new FakeObjectService();
		$objects->saveObject('dossiq', 'bezwaarTrigger', ['id' => 'trig-1', 'archiveTriggerActive' => true]);
		$objects->saveObject('dossiq', 'bezwaarTrigger', ['id' => 'trig-2', 'archiveTriggerActive' => true]);
		$objects->saveObject('dossiq', 'bezwaarTrigger', ['id' => 'trig-3', 'archiveTriggerActive' => false]);

		$timer = $this->createMock(BezwaarArchiveTimer::class);
		$timer->expects($this->exactly(2))->method('sync')->willReturnCallback(
			static fn (array $t): string => $t['id'] === 'trig-2' ? BezwaarArchiveTimer::DUE : BezwaarArchiveTimer::ARMED
		);
		$trigger = $this->createMock(BezwaarArchiveTrigger::class);
		$trigger->expects($this->once())->method('process')->with($this->callback(static fn (array $t): bool => $t['id'] === 'trig-2'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with('Bezwaartermijn timer migration: 1 armed, 1 due and handled, 0 failed.');

		(new ArmBezwaarArchiveTimers(
			settingsService: $this->settings(objects: $objects),
			timer: $timer,
			trigger: $trigger,
			logger: $this->createMock(LoggerInterface::class),
		))->run(output: $output);
	}//end testActiveTriggersAreSyncedAndDueOnesHandled()

	/**
	 * Without OpenRegister the step warns and syncs nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItSkips(): void {
		$timer = $this->createMock(BezwaarArchiveTimer::class);
		$timer->expects($this->never())->method('sync');
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$step = new ArmBezwaarArchiveTimers(
			settingsService: $this->settings(objects: new FakeObjectService(), orUp: false),
			timer: $timer,
			trigger: $this->createMock(BezwaarArchiveTrigger::class),
			logger: $this->createMock(LoggerInterface::class),
		);
		$step->run(output: $output);
		$this->assertSame(expected: 'Arm OpenRegister engine timers for running Dossiq bezwaartermijnen', actual: $step->getName());
	}//end testWithoutOpenRegisterItSkips()
}//end class
