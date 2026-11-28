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
 * @spec openspec/changes/archive/2026-11-21-termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Advice;

use OCA\Dossiq\Service\Advice\AdviceTimer;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Tests\Unit\Service\FlowTimerEngineFake;
use OCA\OpenRegister\Db\FlowTimer;
use DateTimeInterface;
use RuntimeException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Arming, re-arming and cancelling the advice timer.
 *
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Term\DayCount
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
	 * @param LoggerInterface|null     $logger The logger, a silent mock when null.
	 * @param SettingsService|null     $settings A pre-configured settings mock, a fresh one when null.
	 *
	 * @return AdviceTimer
	 */
	private function timer(
		string $reminderDays = '3',
		?FlowTimerEngineFake $engine = null,
		?LoggerInterface $logger = null,
		?SettingsService $settings = null,
	): AdviceTimer {
		$settings ??= $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')
			->with(AdviceTimer::ENGINE_CLASS)
			->willReturn($engine);
		$settings->method('getConfigValue')
			->willReturnCallback(static fn (string $key, string $default = ''): string => $key === 'advice_reminder_days' ? $reminderDays : $default);

		return new AdviceTimer(
			settingsService: $settings,
			dates: $this->caseDatesFrozenAt(instant: '2026-10-10T14:00:00+02:00'),
			logger: $logger ?? $this->createMock(LoggerInterface::class),
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
		// The engine accepts low, medium, high and critical, and refuses the timer otherwise.
		$this->assertSame(
			expected: ['medium', 'medium'],
			actual: array_column($config['escalationRules'], 'priority')
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
	/**
	 * An engine that cancels but refuses to arm is logged with the arm
	 * message and the request id, and answered as unavailable.
	 *
	 * @return void
	 */
	public function testAnEngineRefusingToArmIsLoggedAndUnavailable(): void {
		$engine = new class extends FlowTimerEngineFake {
			/**
			 * Refuse to arm.
			 *
			 * @param array<string, mixed>   $config The timer configuration.
			 * @param string|null            $actor  The arming identity.
			 * @param DateTimeInterface|null $now    The clock.
			 *
			 * @return FlowTimer
			 */
			public function arm(array $config, ?string $actor, ?DateTimeInterface $now = null): FlowTimer {
				throw new RuntimeException('no arming today');
			}
		};
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'Dossiq advice: the engine refused the advice timer',
				['advice' => 'adv-1', 'error' => 'no arming today']
			);

		$result = $this->timer(engine: $engine, logger: $logger)->sync(
			advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-20']
		);

		$this->assertSame(expected: AdviceTimer::UNAVAILABLE, actual: $result);
		$this->assertCount(expectedCount: 1, haystack: $engine->calls['cancelForSubject']);
	}//end testAnEngineRefusingToArmIsLoggedAndUnavailable()

	/**
	 * An engine that vanishes between the cancel and the arm arms nothing.
	 *
	 * @return void
	 */
	public function testAnEngineGoneBeforeArmingIsUnavailable(): void {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->expects($this->exactly(count: 2))
			->method('getOpenRegisterClass')
			->with(AdviceTimer::ENGINE_CLASS)
			->willReturnOnConsecutiveCalls($this->engine, null);

		$timer = new AdviceTimer(
			settingsService: $settings,
			dates: $this->caseDatesFrozenAt(instant: '2026-10-10T14:00:00+02:00'),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		$result = $timer->sync(advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-20']);

		$this->assertSame(expected: AdviceTimer::UNAVAILABLE, actual: $result);
		$this->assertCount(expectedCount: 1, haystack: $this->engine->calls['cancelForSubject']);
		$this->assertArrayNotHasKey(key: 'arm', array: $this->engine->calls);
	}//end testAnEngineGoneBeforeArmingIsUnavailable()

	/**
	 * A refused cancel is logged with the cancel message and stops the re-arm.
	 *
	 * @return void
	 */
	public function testARefusedCancelIsLoggedAndStopsTheRearm(): void {
		$this->engine->refuse = true;
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())
			->method('error')
			->with(
				'Dossiq advice: the engine refused to cancel the advice timer',
				['advice' => 'adv-1', 'error' => 'engine refuses cancelForSubject']
			);

		$result = $this->timer(engine: $this->engine, logger: $logger)->sync(
			advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-20']
		);

		$this->assertSame(expected: AdviceTimer::UNAVAILABLE, actual: $result);
		$this->assertSame(expected: [], actual: $this->engine->calls);
	}//end testARefusedCancelIsLoggedAndStopsTheRearm()

	/**
	 * A closed request whose timers cannot be cancelled is not reported cancelled.
	 *
	 * @return void
	 */
	public function testAClosedRequestWithoutTheEngineIsUnavailable(): void {
		$this->assertSame(
			expected: AdviceTimer::UNAVAILABLE,
			actual: $this->timer(engine: null)->sync(advice: ['id' => 'adv-1', 'status' => 'received'])
		);

		$this->engine->refuse = true;
		$this->assertSame(
			expected: AdviceTimer::UNAVAILABLE,
			actual: $this->timer(engine: $this->engine)->sync(advice: ['id' => 'adv-1', 'status' => 'expired'])
		);
	}//end testAClosedRequestWithoutTheEngineIsUnavailable()

	/**
	 * Cancellation records why: the closing status, or the superseding deadline.
	 *
	 * @return void
	 */
	public function testCancellationsRecordTheirReason(): void {
		$timer = $this->timer(engine: $this->engine);

		$timer->sync(advice: ['id' => 'adv-1', 'status' => 'received']);
		$timer->sync(advice: ['id' => 'adv-2', 'status' => 'requested', 'deadline' => '2026-10-20']);

		$this->assertSame(
			expected: [
				['subjectType' => 'object', 'subjectUuid' => 'adv-1', 'reason' => 'Advice request received', 'actor' => null],
				['subjectType' => 'object', 'subjectUuid' => 'adv-2', 'reason' => 'Superseded by the current deadline', 'actor' => null],
			],
			actual: $this->engine->calls['cancelForSubject']
		);
		$this->assertNull(actual: $this->engine->calls['arm'][0]['actor']);
	}//end testCancellationsRecordTheirReason()

	/**
	 * The id falls back to `uuid`, then to `@self.id`.
	 *
	 * @return void
	 */
	public function testTheIdFallsBackToUuidThenSelf(): void {
		$timer = $this->timer(engine: $this->engine);

		$timer->sync(advice: ['uuid' => 'adv-uuid', 'status' => 'received']);
		$timer->sync(advice: ['@self' => ['id' => 'adv-self'], 'status' => 'received']);
		$timer->sync(advice: ['id' => 'adv-id', 'uuid' => 'ignored', 'status' => 'received']);

		$this->assertSame(
			expected: ['adv-uuid', 'adv-self', 'adv-id'],
			actual: array_column($this->engine->calls['cancelForSubject'], 'subjectUuid')
		);
	}//end testTheIdFallsBackToUuidThenSelf()

	/**
	 * A status that is neither open nor closed is left alone, deadline or not.
	 *
	 * @return void
	 */
	public function testAnUnknownStatusIsSkippedWithoutTouchingTheEngine(): void {
		$result = $this->timer(engine: $this->engine)->sync(
			advice: ['id' => 'adv-1', 'status' => 'draft', 'deadline' => '2026-10-20']
		);

		$this->assertSame(expected: AdviceTimer::SKIPPED, actual: $result);
		$this->assertSame(expected: [], actual: $this->engine->calls);
	}//end testAnUnknownStatusIsSkippedWithoutTouchingTheEngine()

	/**
	 * The title names the subject, else the id; the metadata ties back to the case.
	 *
	 * @return void
	 */
	public function testTitleAndMetadataNameTheRequestAndCase(): void {
		$timer = $this->timer(engine: $this->engine);

		$timer->sync(advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-20', 'subject' => 'Bouwplan', 'case' => 'case-9']);
		$timer->sync(advice: ['id' => 'adv-2', 'status' => 'requested', 'deadline' => '2026-10-20']);

		$first  = $this->engine->calls['arm'][0]['config'];
		$second = $this->engine->calls['arm'][1]['config'];
		$this->assertSame(expected: 'Adviestermijn Bouwplan', actual: $first['title']);
		$this->assertSame(expected: 'advice_requested', actual: $first['anchorEvent']);
		$this->assertSame(
			expected: ['source' => AdviceTimer::METADATA_SOURCE, 'adviceId' => 'adv-1', 'caseId' => 'case-9'],
			actual: $first['metadata']
		);
		$this->assertSame(expected: 'Adviestermijn adv-2', actual: $second['title']);
		$this->assertSame(expected: '', actual: $second['metadata']['caseId']);
	}//end testTitleAndMetadataNameTheRequestAndCase()

	/**
	 * Both rungs carry the engine's full rule shape and their own message.
	 *
	 * @return void
	 */
	public function testTheRungsCarryTheFullRuleShape(): void {
		$this->timer(reminderDays: '5', engine: $this->engine)->sync(
			advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-20']
		);

		$this->assertSame(
			expected: [
				[
					'trigger'        => 'slaBreached',
					'offset'         => 0,
					'offsetUnit'     => 'calendarDays',
					'notifyRole'     => [],
					'escalateToRole' => [],
					'priority'       => 'medium',
					'message'        => 'advies-verlopen',
					'openIncident'   => false,
				],
				[
					'trigger'        => 'preBreach',
					'offset'         => 6,
					'offsetUnit'     => 'calendarDays',
					'notifyRole'     => [],
					'escalateToRole' => [],
					'priority'       => 'medium',
					'message'        => 'advies-herinnering',
					'openIncident'   => false,
				],
			],
			actual: $this->engine->calls['arm'][0]['config']['escalationRules']
		);
	}//end testTheRungsCarryTheFullRuleShape()

	/**
	 * A reminder lead equal to the SLA would sit on the anchor, so it is left out.
	 *
	 * @return void
	 */
	public function testAReminderOnTheAnchorDayIsLeftOut(): void {
		// Deadline 13 Oct: SLA 4, lead 3 + 1 = 4, not before the anchor.
		$this->timer(engine: $this->engine)->sync(
			advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-13']
		);
		// Deadline 14 Oct: SLA 5, lead 4 is still ahead.
		$this->timer(engine: $this->engine)->sync(
			advice: ['id' => 'adv-2', 'status' => 'requested', 'deadline' => '2026-10-14']
		);

		$this->assertCount(expectedCount: 1, haystack: $this->engine->calls['arm'][0]['config']['escalationRules']);
		$this->assertCount(expectedCount: 2, haystack: $this->engine->calls['arm'][1]['config']['escalationRules']);
	}//end testAReminderOnTheAnchorDayIsLeftOut()
}//end class
