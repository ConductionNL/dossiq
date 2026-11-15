<?php

/**
 * RearmBeslistermijnTimers unit tests (REQ-OTE-05, REQ-OTE-08).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-existing-cases-are-repaired-once-req-ote-08
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RearmBeslistermijnTimers;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\Termijn\TermInstanceStore;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Repair\RearmBeslistermijnTimers
 * @uses   \OCA\Dossiq\Service\Termijn\CaseDeadlineMirror
 * @uses   \OCA\Dossiq\Service\Termijn\TermInstanceStore
 * @uses   \OCA\Dossiq\Service\TermKind
 */
class RearmBeslistermijnTimersTest extends TestCase {

	/**
	 * Only a running statutory term with an old timer is re-armed, and marked.
	 *
	 * @return void
	 */
	public function testOnlyARunningStatutoryTermWithAnOldTimerIsRearmed(): void {
		$objects = new FakeTermijnStore();
		$objects->seed('deadlineInstance', ['id' => 'old', 'case' => 'c1', 'status' => 'lopend', 'engineTimerId' => 'timer-old']);
		$objects->seed('deadlineInstance', ['id' => 'marked', 'case' => 'c2', 'status' => 'verlengd', 'engineTimerId' => 'timer-new', 'timerBreachesAfterLastDay' => true]);
		$objects->seed('deadlineInstance', ['id' => 'paused', 'case' => 'c3', 'status' => 'paused', 'engineTimerId' => 'timer-p']);
		$objects->seed('deadlineInstance', ['id' => 'planned', 'case' => 'c4', 'kind' => 'planned', 'status' => 'lopend', 'engineTimerId' => 'timer-x']);
		$objects->seed('deadlineInstance', ['id' => 'notimer', 'case' => 'c5', 'status' => 'lopend']);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('isOpenRegisterAvailable')->willReturn(true);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => match ($key) {
				'register' => 'dossiq',
				'termijn_instance_schema' => 'deadlineInstance',
				default => '',
			}
		);
		$logger = $this->createMock(LoggerInterface::class);

		$timers = $this->createMock(TermijnTimerService::class);
		$timers->expects(self::once())->method('cancelForInstance')->with('old', self::anything())->willReturn(1);
		$timers->expects(self::once())->method('armBeslistermijn')->willReturn('timer-rearmed');
		$terms = $this->createMock(TermijnService::class);
		$terms->expects(self::once())->method('updateTermijnInstance')
			->with('old', ['engineTimerId' => 'timer-rearmed', 'timerBreachesAfterLastDay' => true]);

		$step = new RearmBeslistermijnTimers(
			settingsService: $settings,
			termService: $terms,
			timerService: $timers,
			mirror: new CaseDeadlineMirror(
				settingsService: $settings,
				store: new TermInstanceStore(settingsService: $settings, logger: $logger),
				logger: $logger,
			),
			logger: $logger,
		);

		$messages = [];
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(
			static function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);

		$step->run($output);

		self::assertSame(['Decision term timers: 1 re-armed, 4 already right, 0 failed.'], $messages);
	}//end testOnlyARunningStatutoryTermWithAnOldTimerIsRearmed()
}//end class
