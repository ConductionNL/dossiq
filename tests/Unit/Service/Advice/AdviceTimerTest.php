<?php

/**
 * Tests for the advice deadline on an armed engine timer.
 *
 * The date arithmetic is what moves here, so it is pinned against the job it
 * replaces: AdviceDeadlineJob expired an advice request on the first day
 * AFTER its deadline, and reminded on the day `advice_reminder_days` before
 * it. The timer must fire on exactly those days, or the advisor is chased a
 * day early and the request expires on its last day.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Advice
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

namespace OCA\Dossiq\Tests\Unit\Service\Advice;

use OCA\Dossiq\Service\Advice\AdviceTimer;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Unit\Service\FlowTimerEngineFake;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Arming, re-arming and cancelling the advice timer.
 *
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 */
class AdviceTimerTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The engine fake, with the real FlowTimerService signatures.
	 *
	 * @var FlowTimerEngineFake
	 */
	private FlowTimerEngineFake $engine;

	/**
	 * The service under test, on a clock frozen at 10 Oct 2026, 14:00 in Amsterdam.
	 *
	 * @param string             $reminderDays The stored `advice_reminder_days`.
	 * @param FlowTimerEngineFake|null $engine The engine, null for an absent one.
	 *
	 * @return AdviceTimer
	 */
	private function timer(string $reminderDays = '3', ?FlowTimerEngineFake $engine = null): AdviceTimer {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')
			->with(AdviceTimer::ENGINE_CLASS)
			->willReturn($engine);
		$settings->method('getConfigValue')
			->willReturnCallback(static fn (string $key, string $default = ''): string => $key === 'advice_reminder_days' ? $reminderDays : $default);

		return new AdviceTimer(
			settingsService: $settings,
			dates: $this->caseDatesFrozenAt(instant: '2026-10-10T14:00:00+02:00'),
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end timer()

	/**
	 * Build the engine fake.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->engine = new FlowTimerEngineFake();
	}//end setUp()

	/**
	 * An open request is armed to expire the day after its deadline, and to
	 * remind on the day the reminder setting names before it.
	 *
	 * @return void
	 */
	public function testAnOpenRequestIsArmedOnTheJobsDays(): void {
		$result = $this->timer(engine: $this->engine)->sync(
			advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-20', 'case' => 'case-1']
		);

		$this->assertSame(expected: AdviceTimer::ARMED, actual: $result);
		$config = $this->engine->calls['arm'][0]['config'];
		$this->assertSame(expected: 'object', actual: $config['subjectType']);
		$this->assertSame(expected: 'adv-1', actual: $config['subjectUuid']);
		$this->assertSame(expected: 'dossiq', actual: $config['appId']);
		$this->assertSame(expected: 'due', actual: $config['purpose']);
		$this->assertSame(expected: 'none', actual: $config['legalEffect']);
		// Anchored at midnight today: 10 days to the deadline, plus the day
		// itself, so it fires at the start of 21 October, as the job did.
		$this->assertSame(expected: '2026-10-10T00:00:00+02:00', actual: $config['anchorEventAt']->format('c'));
		$this->assertSame(expected: ['value' => 11, 'unit' => 'calendarDays'], actual: $config['sla']);
		$this->assertSame(
			expected: ['slaBreached:0', 'preBreach:4'],
			actual: array_map(static fn (array $r): string => $r['trigger'].':'.$r['offset'], $config['escalationRules'])
		);
		$this->assertSame(expected: AdviceTimer::METADATA_SOURCE, actual: $config['metadata']['source']);
		$this->assertSame(expected: 'adv-1', actual: $config['metadata']['adviceId']);
	}//end testAnOpenRequestIsArmedOnTheJobsDays()

	/**
	 * Arming supersedes: the open timers of the request are cancelled first,
	 * so a moved deadline never leaves the old one running beside the new.
	 *
	 * @return void
	 */
	public function testArmingCancelsTheEarlierTimerFirst(): void {
		$this->timer(engine: $this->engine)->sync(
			advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-20']
		);

		$this->assertSame(expected: 'adv-1', actual: $this->engine->calls['cancelForSubject'][0]['subjectUuid']);
		$this->assertCount(expectedCount: 1, haystack: $this->engine->calls['arm']);
	}//end testArmingCancelsTheEarlierTimerFirst()

	/**
	 * A deadline inside the reminder window gets no reminder rung, because the
	 * job would never have reminded it either and the engine refuses a rung
	 * before its anchor.
	 *
	 * @return void
	 */
	public function testADeadlineInsideTheReminderWindowGetsNoReminder(): void {
		$this->timer(engine: $this->engine)->sync(
			advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-12']
		);

		$config = $this->engine->calls['arm'][0]['config'];
		$this->assertSame(expected: ['value' => 3, 'unit' => 'calendarDays'], actual: $config['sla']);
		$this->assertCount(expectedCount: 1, haystack: $config['escalationRules']);
		$this->assertSame(expected: 'slaBreached', actual: $config['escalationRules'][0]['trigger']);
	}//end testADeadlineInsideTheReminderWindowGetsNoReminder()

	/**
	 * A deadline due today still runs until midnight.
	 *
	 * @return void
	 */
	public function testADeadlineTodayExpiresTomorrow(): void {
		$result = $this->timer(engine: $this->engine)->sync(
			advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-10']
		);

		$this->assertSame(expected: AdviceTimer::ARMED, actual: $result);
		$this->assertSame(expected: ['value' => 1, 'unit' => 'calendarDays'], actual: $this->engine->calls['arm'][0]['config']['sla']);
	}//end testADeadlineTodayExpiresTomorrow()

	/**
	 * A deadline already past arms nothing and says so, so the caller expires
	 * the request now rather than a day late.
	 *
	 * @return void
	 */
	public function testAPastDeadlineIsOverdue(): void {
		$result = $this->timer(engine: $this->engine)->sync(
			advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-09']
		);

		$this->assertSame(expected: AdviceTimer::OVERDUE, actual: $result);
		$this->assertArrayNotHasKey(key: 'arm', array: $this->engine->calls);
		$this->assertSame(expected: 'adv-1', actual: $this->engine->calls['cancelForSubject'][0]['subjectUuid']);
	}//end testAPastDeadlineIsOverdue()

	/**
	 * A received or expired request has its timers cancelled.
	 *
	 * @return void
	 */
	public function testAClosedRequestIsCancelled(): void {
		foreach (['received', 'expired'] as $status) {
			$engine = new FlowTimerEngineFake();
			$result = $this->timer(engine: $engine)->sync(
				advice: ['id' => 'adv-1', 'status' => $status, 'deadline' => '2026-10-20']
			);

			$this->assertSame(expected: AdviceTimer::CANCELLED, actual: $result, message: $status);
			$this->assertArrayNotHasKey(key: 'arm', array: $engine->calls, message: $status);
			$this->assertCount(expectedCount: 1, haystack: $engine->calls['cancelForSubject'], message: $status);
		}
	}//end testAClosedRequestIsCancelled()

	/**
	 * A request without a deadline or an id has nothing to time.
	 *
	 * @return void
	 */
	public function testNothingToTimeIsSkipped(): void {
		$timer = $this->timer(engine: $this->engine);

		$this->assertSame(expected: AdviceTimer::SKIPPED, actual: $timer->sync(advice: ['id' => 'adv-1', 'status' => 'requested']));
		$this->assertSame(expected: AdviceTimer::SKIPPED, actual: $timer->sync(advice: ['status' => 'requested', 'deadline' => '2026-10-20']));
		$this->assertSame(expected: [], actual: $this->engine->calls);
	}//end testNothingToTimeIsSkipped()

	/**
	 * A reminder setting that does not parse reads as three days, as the job read it.
	 *
	 * @return void
	 */
	public function testAnUnusableReminderSettingReadsAsThree(): void {
		$this->timer(reminderDays: '0', engine: $this->engine)->sync(
			advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-20']
		);

		$this->assertSame(expected: 4, actual: $this->engine->calls['arm'][0]['config']['escalationRules'][1]['offset']);
	}//end testAnUnusableReminderSettingReadsAsThree()

	/**
	 * Without OpenRegister's engine nothing is armed and nothing throws.
	 *
	 * @return void
	 */
	public function testWithoutTheEngineItIsUnavailable(): void {
		$result = $this->timer(engine: null)->sync(
			advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-20']
		);

		$this->assertSame(expected: AdviceTimer::UNAVAILABLE, actual: $result);
	}//end testWithoutTheEngineItIsUnavailable()

	/**
	 * An engine that refuses is logged and answered, never thrown at the caller.
	 *
	 * @return void
	 */
	public function testARefusingEngineIsUnavailable(): void {
		$this->engine->refuse = true;

		$result = $this->timer(engine: $this->engine)->sync(
			advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-20']
		);

		$this->assertSame(expected: AdviceTimer::UNAVAILABLE, actual: $result);
	}//end testARefusingEngineIsUnavailable()
}//end class
