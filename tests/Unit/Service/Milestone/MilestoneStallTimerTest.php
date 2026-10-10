<?php

/**
 * Tests for the milestone stall on an armed engine timer.
 *
 * The day that moves is the one the assignee is told on. StalledCaseDetector
 * calls a case stalled from the first day after the milestone's deadline
 * (`daysOverdue > 0`), so the timer breaches at the start of that day.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Milestone
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

namespace OCA\Dossiq\Tests\Unit\Service\Milestone;

use OCA\Dossiq\Service\Milestone\MilestoneStallTimer;
use OCA\Dossiq\Service\Milestone\StalledCaseDetector;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Unit\Service\FlowTimerEngineFake;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Arming on the milestone's deadline, stalled cases, cases waiting on nothing.
 *
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 */
class MilestoneStallTimerTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The service over a detector that answers the given milestone row.
	 *
	 * @param array<string, mixed>|null $waiting The row waitingOn() answers.
	 * @param FlowTimerEngineFake|null  $engine  The engine, null for none.
	 *
	 * @return MilestoneStallTimer
	 */
	private function timer(?array $waiting, ?FlowTimerEngineFake $engine): MilestoneStallTimer {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->with(MilestoneStallTimer::ENGINE_CLASS)->willReturn($engine);
		$detector = $this->createMock(StalledCaseDetector::class);
		$detector->method('waitingOn')->willReturn($waiting);

		return new MilestoneStallTimer(
			settingsService: $settings,
			detector: $detector,
			dates: $this->caseDatesFrozenAt(instant: '2026-10-10T14:00:00+02:00'),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end timer()

	/**
	 * A case waiting on a milestone due 20 Oct breaches at the start of 21 Oct.
	 *
	 * @return void
	 */
	public function testAWaitingCaseBreachesTheDayAfterTheDeadline(): void {
		$engine = new FlowTimerEngineFake();
		$result = $this->timer(
			waiting: ['caseId' => 'case-1', 'milestoneIdentifier' => 'documenten_compleet', 'milestoneLabel' => 'Documenten compleet', 'deadline' => '2026-10-20', 'daysOverdue' => -10],
			engine: $engine
		)->sync(case: ['id' => 'case-1']);

		$this->assertSame(expected: MilestoneStallTimer::ARMED, actual: $result);
		$config = $engine->calls['arm'][0]['config'];
		$this->assertSame(expected: 'case-1:milestone', actual: $config['subjectUuid']);
		$this->assertSame(expected: ['value' => 11, 'unit' => 'calendarDays'], actual: $config['sla']);
		$this->assertSame(expected: 'documenten_compleet', actual: $config['metadata']['milestoneIdentifier']);
		$this->assertSame(expected: 'case-1:milestone', actual: $engine->calls['cancelForSubject'][0]['subjectUuid']);
	}//end testAWaitingCaseBreachesTheDayAfterTheDeadline()

	/**
	 * A milestone whose deadline was yesterday is stalled now; one due today is not yet.
	 *
	 * @return void
	 */
	public function testAPastDeadlineIsDue(): void {
		$engine = new FlowTimerEngineFake();
		$this->assertSame(
			expected: MilestoneStallTimer::DUE,
			actual: $this->timer(waiting: ['deadline' => '2026-10-09'], engine: $engine)->sync(case: ['id' => 'case-1'])
		);
		$this->assertArrayNotHasKey(key: 'arm', array: $engine->calls);

		$today = new FlowTimerEngineFake();
		$this->assertSame(
			expected: MilestoneStallTimer::ARMED,
			actual: $this->timer(waiting: ['deadline' => '2026-10-10'], engine: $today)->sync(case: ['id' => 'case-1'])
		);
		$this->assertSame(expected: ['value' => 1, 'unit' => 'calendarDays'], actual: $today->calls['arm'][0]['config']['sla']);
	}//end testAPastDeadlineIsDue()

	/**
	 * A case waiting on nothing has its milestone timer cancelled.
	 *
	 * @return void
	 */
	public function testACaseWaitingOnNothingIsCancelled(): void {
		$engine = new FlowTimerEngineFake();

		$this->assertSame(expected: MilestoneStallTimer::CANCELLED, actual: $this->timer(waiting: null, engine: $engine)->sync(case: ['id' => 'case-1']));
		$this->assertArrayNotHasKey(key: 'arm', array: $engine->calls);
		$this->assertSame(expected: 'case-1:milestone', actual: $engine->calls['cancelForSubject'][0]['subjectUuid']);
	}//end testACaseWaitingOnNothingIsCancelled()

	/**
	 * No id, no engine and a refusing engine are answered.
	 *
	 * @return void
	 */
	public function testNothingToTimeOrNoEngine(): void {
		$this->assertSame(expected: MilestoneStallTimer::SKIPPED, actual: $this->timer(waiting: null, engine: new FlowTimerEngineFake())->sync(case: []));
		$this->assertSame(expected: MilestoneStallTimer::UNAVAILABLE, actual: $this->timer(waiting: ['deadline' => '2026-10-20'], engine: null)->sync(case: ['id' => 'case-1']));
		$refusing         = new FlowTimerEngineFake();
		$refusing->refuse = true;
		$this->assertSame(expected: MilestoneStallTimer::UNAVAILABLE, actual: $this->timer(waiting: ['deadline' => '2026-10-20'], engine: $refusing)->sync(case: ['id' => 'case-1']));
	}//end testNothingToTimeOrNoEngine()
}//end class
