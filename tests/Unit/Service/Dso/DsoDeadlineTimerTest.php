<?php

/**
 * Tests for the DSO decision term on an armed engine timer.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Dso
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

namespace OCA\Dossiq\Tests\Unit\Service\Dso;

use OCA\Dossiq\Service\Dso\DsoDeadlineTimer;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Unit\Service\FlowTimerEngineFake;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The DSO term timer: the job's days, its own subject, open and closed cases.
 *
 * The subject is asserted as hard as the dates: a DSO timer armed on the
 * case id would be cancelled by every status change, because status dwell
 * timers are cancelled by subject on the case id.
 *
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 */
class DsoDeadlineTimerTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The service on a clock frozen at 10 Oct 2026, 14:00 in Amsterdam.
	 *
	 * @param FlowTimerEngineFake|null $engine   The engine, null for none.
	 * @param array<string, string>    $settings Stored app config.
	 *
	 * @return DsoDeadlineTimer
	 */
	private function timer(?FlowTimerEngineFake $engine, array $settings = []): DsoDeadlineTimer {
		$mock = $this->createMock(SettingsService::class);
		$mock->method('getOpenRegisterClass')->with(DsoDeadlineTimer::ENGINE_CLASS)->willReturn($engine);
		$mock->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => $settings[$key] ?? $default);

		return new DsoDeadlineTimer(
			settingsService: $mock,
			dates: $this->caseDatesFrozenAt(instant: '2026-10-10T14:00:00+02:00'),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end timer()

	/**
	 * An open DSO case breaches on its deadline day, with the two bands in working days.
	 *
	 * @return void
	 */
	public function testAnOpenCaseIsArmedOnTheJobsDays(): void {
		$engine = new FlowTimerEngineFake();
		$result = $this->timer(engine: $engine)->sync(case: ['id' => 'case-1', 'dsoStatus' => 'in_handling', 'deadlineDate' => '2026-12-31']);

		$this->assertSame(expected: DsoDeadlineTimer::ARMED, actual: $result);
		$config = $engine->calls['arm'][0]['config'];
		$this->assertSame(expected: 'case-1:dso', actual: $config['subjectUuid']);
		$this->assertSame(expected: '2026-10-10T00:00:00+02:00', actual: $config['anchorEventAt']->format('c'));
		$this->assertSame(expected: ['value' => 82, 'unit' => 'calendarDays'], actual: $config['sla']);
		$this->assertSame(
			expected: ['slaBreached:0:calendarDays', 'preBreach:14:businessDays', 'preBreach:5:businessDays'],
			actual: array_map(static fn (array $r): string => $r['trigger'].':'.$r['offset'].':'.$r['offsetUnit'], $config['escalationRules'])
		);
		$this->assertSame(expected: DsoDeadlineTimer::MESSAGE_WARNING, actual: $config['escalationRules'][1]['message']);
		$this->assertSame(expected: DsoDeadlineTimer::MESSAGE_CRITICAL, actual: $config['escalationRules'][2]['message']);
		$this->assertSame(expected: 'case-1', actual: $config['metadata']['caseId']);
		$this->assertSame(expected: 'case-1:dso', actual: $engine->calls['cancelForSubject'][0]['subjectUuid']);
	}//end testAnOpenCaseIsArmedOnTheJobsDays()

	/**
	 * The two band settings are read as working days, unusable ones as the job's defaults.
	 *
	 * @return void
	 */
	public function testTheBandSettingsAreRead(): void {
		$engine = new FlowTimerEngineFake();
		$this->timer(engine: $engine, settings: ['dso_deadline_warning_weeks_warning' => '20', 'dso_deadline_warning_weeks_critical' => '0'])
			->sync(case: ['id' => 'case-1', 'dsoStatus' => 'submitted', 'deadlineDate' => '2026-12-31']);

		$rules = $engine->calls['arm'][0]['config']['escalationRules'];
		$this->assertSame(expected: 20, actual: $rules[1]['offset']);
		$this->assertSame(expected: 5, actual: $rules[2]['offset']);
	}//end testTheBandSettingsAreRead()

	/**
	 * A deadline today or past is due now; the job called the deadline day overdue.
	 *
	 * @return void
	 */
	public function testADeadlineTodayIsDue(): void {
		foreach (['2026-10-10', '2026-09-30'] as $date) {
			$engine = new FlowTimerEngineFake();
			$result = $this->timer(engine: $engine)->sync(case: ['id' => 'case-1', 'dsoStatus' => 'in_handling', 'deadlineDate' => $date]);
			$this->assertSame(expected: DsoDeadlineTimer::DUE, actual: $result, message: $date);
			$this->assertArrayNotHasKey(key: 'arm', array: $engine->calls, message: $date);
		}
	}//end testADeadlineTodayIsDue()

	/**
	 * A case out of the open statuses, or without a deadline, has only its DSO timer cancelled.
	 *
	 * @return void
	 */
	public function testAClosedCaseIsCancelled(): void {
		foreach ([['dsoStatus' => 'decided', 'deadlineDate' => '2026-12-31'], ['dsoStatus' => 'in_handling']] as $fields) {
			$engine = new FlowTimerEngineFake();
			$result = $this->timer(engine: $engine)->sync(case: ['id' => 'case-1'] + $fields);
			$this->assertSame(expected: DsoDeadlineTimer::CANCELLED, actual: $result);
			$this->assertSame(expected: 'case-1:dso', actual: $engine->calls['cancelForSubject'][0]['subjectUuid']);
			$this->assertArrayNotHasKey(key: 'arm', array: $engine->calls);
		}
	}//end testAClosedCaseIsCancelled()

	/**
	 * No id, no engine and a refusing engine are answered, not thrown.
	 *
	 * @return void
	 */
	public function testNothingToTimeOrNoEngine(): void {
		$this->assertSame(expected: DsoDeadlineTimer::SKIPPED, actual: $this->timer(engine: new FlowTimerEngineFake())->sync(case: ['dsoStatus' => 'submitted']));
		$this->assertSame(
			expected: DsoDeadlineTimer::UNAVAILABLE,
			actual: $this->timer(engine: null)->sync(case: ['id' => 'case-1', 'dsoStatus' => 'submitted', 'deadlineDate' => '2026-12-31'])
		);
		$refusing         = new FlowTimerEngineFake();
		$refusing->refuse = true;
		$this->assertSame(
			expected: DsoDeadlineTimer::UNAVAILABLE,
			actual: $this->timer(engine: $refusing)->sync(case: ['id' => 'case-1', 'dsoStatus' => 'submitted', 'deadlineDate' => '2026-12-31'])
		);
	}//end testNothingToTimeOrNoEngine()
}//end class
