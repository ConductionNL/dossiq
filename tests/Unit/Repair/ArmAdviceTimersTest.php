<?php

/**
 * Tests for arming the advice timers of requests already waiting.
 *
 * AdviceDeadlineJob retires in the same release, so a request that was open
 * before the upgrade has no timer unless this step arms one. Without it
 * every in-flight request would silently never be reminded or expired.
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

use OCA\Dossiq\Repair\ArmAdviceTimers;
use OCA\Dossiq\Service\Advice\AdviceTimer;
use OCA\Dossiq\Service\AdviceService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Each open request synced once, overdue ones expired, OpenRegister absent skipped.
 */
class ArmAdviceTimersTest extends TestCase {

	/**
	 * The step over the given open requests and sync outcomes.
	 *
	 * @param array<int, array<string, mixed>> $open     The open requests.
	 * @param array<string, string>            $outcomes Sync outcome per request id.
	 * @param AdviceService|null               $advice   The advice service.
	 * @param bool                             $orUp     Whether OpenRegister is available.
	 *
	 * @return ArmAdviceTimers
	 */
	private function step(array $open, array $outcomes, ?AdviceService $advice = null, bool $orUp = true): ArmAdviceTimers {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn($orUp);
		$settings->method('getObjectService')->willReturn(null);

		$advice ??= $this->createMock(AdviceService::class);
		$advice->method('getOpenAdvice')->willReturn($open);

		$timer = $this->createMock(AdviceTimer::class);
		$timer->method('sync')->willReturnCallback(static fn (array $a): string => $outcomes[$a['id']] ?? AdviceTimer::SKIPPED);

		return new ArmAdviceTimers(
			settingsService: $settings,
			adviceService: $advice,
			timer: $timer,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end step()

	/**
	 * Every open request is synced, and the counts say what happened.
	 *
	 * @return void
	 */
	public function testEveryOpenRequestIsSyncedAndCounted(): void {
		$advice = $this->createMock(AdviceService::class);
		$advice->expects($this->once())->method('expireAdvice')->with('adv-3');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')
			->with('Advice timer migration: 2 armed, 1 expired, 1 failed.');

		$this->step(
			open: [['id' => 'adv-1'], ['id' => 'adv-2'], ['id' => 'adv-3'], ['id' => 'adv-4']],
			outcomes: ['adv-1' => AdviceTimer::ARMED, 'adv-2' => AdviceTimer::ARMED, 'adv-3' => AdviceTimer::OVERDUE, 'adv-4' => AdviceTimer::UNAVAILABLE],
			advice: $advice
		)->run(output: $output);
	}//end testEveryOpenRequestIsSyncedAndCounted()

	/**
	 * Without OpenRegister the step warns and reads nothing.
	 *
	 * @return void
	 */
	public function testWithoutOpenRegisterItSkips(): void {
		$advice = $this->createMock(AdviceService::class);
		$advice->expects($this->never())->method('getOpenAdvice');

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning');

		$this->step(open: [], outcomes: [], advice: $advice, orUp: false)->run(output: $output);
	}//end testWithoutOpenRegisterItSkips()

	/**
	 * The step names itself.
	 *
	 * @return void
	 */
	public function testItNamesItself(): void {
		$this->assertSame(
			expected: 'Arm OpenRegister engine timers for open Dossiq advice requests',
			actual: $this->step(open: [], outcomes: [])->getName()
		);
	}//end testItNamesItself()
}//end class
