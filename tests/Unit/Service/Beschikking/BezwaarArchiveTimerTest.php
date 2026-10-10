<?php

/**
 * Tests for the bezwaartermijn on an armed engine timer.
 *
 * The date that moves is the one the archive happens on. BezwaarTermijnJob
 * acted on the first run where `archiveDate <= today`, so the timer must
 * breach at the start of the archive date: a day early files a beschikking
 * while an objection may still come in, a day late is a day the dossier sits
 * open for nothing.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Beschikking
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

namespace OCA\Dossiq\Tests\Unit\Service\Beschikking;

use OCA\Dossiq\Service\Beschikking\BezwaarArchiveTimer;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Unit\Service\FlowTimerEngineFake;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Arming on the bekendmaking, due triggers, switched-off triggers.
 *
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 */
class BezwaarArchiveTimerTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The engine fake.
	 *
	 * @var FlowTimerEngineFake
	 */
	private FlowTimerEngineFake $engine;

	/**
	 * Build the engine fake.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->engine = new FlowTimerEngineFake();
	}//end setUp()

	/**
	 * The service, on a clock frozen at 10 Oct 2026, 14:00 in Amsterdam.
	 *
	 * @param FlowTimerEngineFake|null $engine The engine, null for an absent one.
	 *
	 * @return BezwaarArchiveTimer
	 */
	private function timer(?FlowTimerEngineFake $engine): BezwaarArchiveTimer {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->with(BezwaarArchiveTimer::ENGINE_CLASS)->willReturn($engine);

		return new BezwaarArchiveTimer(
			settingsService: $settings,
			dates: $this->caseDatesFrozenAt(instant: '2026-10-10T14:00:00+02:00'),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end timer()

	/**
	 * An active trigger is anchored on the bekendmaking and breaches on the archive date.
	 *
	 * @return void
	 */
	public function testAnActiveTriggerBreachesOnItsArchiveDate(): void {
		$result = $this->timer(engine: $this->engine)->sync(
			trigger: [
				'id'                   => 'trig-1',
				'decisionId'           => 'besch-1',
				'announcementDate'     => '2026-10-01',
				'objectionTermEndDate' => '2026-11-12',
				'archiveDate'          => '2026-11-13',
				'archiveTriggerActive' => true,
			]
		);

		$this->assertSame(expected: BezwaarArchiveTimer::ARMED, actual: $result);
		$config = $this->engine->calls['arm'][0]['config'];
		$this->assertSame(expected: 'trig-1', actual: $config['subjectUuid']);
		$this->assertSame(expected: 'bekendmaking', actual: $config['anchorEvent']);
		$this->assertSame(expected: '2026-10-01T00:00:00+02:00', actual: $config['anchorEventAt']->format('c'));
		// 1 Oct to 13 Nov is 43 calendar days: the breach is midnight of 13 Nov.
		$this->assertSame(expected: ['value' => 43, 'unit' => 'calendarDays'], actual: $config['sla']);
		$this->assertSame(expected: 'slaBreached', actual: $config['escalationRules'][0]['trigger']);
		$this->assertSame(expected: BezwaarArchiveTimer::METADATA_SOURCE, actual: $config['metadata']['source']);
		$this->assertSame(expected: 'trig-1', actual: $config['metadata']['triggerId']);
		$this->assertSame(expected: 'trig-1', actual: $this->engine->calls['cancelForSubject'][0]['subjectUuid']);
	}//end testAnActiveTriggerBreachesOnItsArchiveDate()

	/**
	 * Without a usable bekendmaking the timer is anchored today and still breaches on the archive date.
	 *
	 * @return void
	 */
	public function testWithoutABekendmakingItIsAnchoredToday(): void {
		$this->timer(engine: $this->engine)->sync(
			trigger: ['id' => 'trig-1', 'archiveDate' => '2026-10-20', 'archiveTriggerActive' => true]
		);

		$config = $this->engine->calls['arm'][0]['config'];
		$this->assertSame(expected: '2026-10-10T00:00:00+02:00', actual: $config['anchorEventAt']->format('c'));
		$this->assertSame(expected: ['value' => 10, 'unit' => 'calendarDays'], actual: $config['sla']);
	}//end testWithoutABekendmakingItIsAnchoredToday()

	/**
	 * An archive date today or past is due now, as the job would have acted on it.
	 *
	 * @return void
	 */
	public function testAnArchiveDateTodayOrPastIsDue(): void {
		foreach (['2026-10-10', '2026-10-01'] as $date) {
			$engine = new FlowTimerEngineFake();
			$result = $this->timer(engine: $engine)->sync(
				trigger: ['id' => 'trig-1', 'archiveDate' => $date, 'archiveTriggerActive' => true]
			);

			$this->assertSame(expected: BezwaarArchiveTimer::DUE, actual: $result, message: $date);
			$this->assertArrayNotHasKey(key: 'arm', array: $engine->calls, message: $date);
		}
	}//end testAnArchiveDateTodayOrPastIsDue()

	/**
	 * A switched-off trigger has its timers cancelled.
	 *
	 * @return void
	 */
	public function testASwitchedOffTriggerIsCancelled(): void {
		$result = $this->timer(engine: $this->engine)->sync(
			trigger: ['id' => 'trig-1', 'archiveDate' => '2026-11-13', 'archiveTriggerActive' => false]
		);

		$this->assertSame(expected: BezwaarArchiveTimer::CANCELLED, actual: $result);
		$this->assertArrayNotHasKey(key: 'arm', array: $this->engine->calls);
		$this->assertCount(expectedCount: 1, haystack: $this->engine->calls['cancelForSubject']);
	}//end testASwitchedOffTriggerIsCancelled()

	/**
	 * Nothing to time, and no engine, are answered rather than thrown.
	 *
	 * @return void
	 */
	public function testNothingToTimeOrNoEngine(): void {
		$timer = $this->timer(engine: $this->engine);
		$this->assertSame(expected: BezwaarArchiveTimer::SKIPPED, actual: $timer->sync(trigger: ['id' => 'trig-1', 'archiveTriggerActive' => true]));
		$this->assertSame(expected: BezwaarArchiveTimer::SKIPPED, actual: $timer->sync(trigger: ['archiveDate' => '2026-11-13', 'archiveTriggerActive' => true]));

		$this->assertSame(
			expected: BezwaarArchiveTimer::UNAVAILABLE,
			actual: $this->timer(engine: null)->sync(trigger: ['id' => 'trig-1', 'archiveDate' => '2026-11-13', 'archiveTriggerActive' => true])
		);

		$refusing         = new FlowTimerEngineFake();
		$refusing->refuse = true;
		$this->assertSame(
			expected: BezwaarArchiveTimer::UNAVAILABLE,
			actual: $this->timer(engine: $refusing)->sync(trigger: ['id' => 'trig-1', 'archiveDate' => '2026-11-13', 'archiveTriggerActive' => true])
		);
	}//end testNothingToTimeOrNoEngine()
}//end class
