<?php

/**
 * Golden tests for the timer configuration and refusal logs of the engine timers.
 *
 * Pins the whole configuration array each timer hands the engine, in key
 * order, and the exact log call each one makes when the engine refuses to
 * arm or to cancel, so the shared timer plumbing cannot drift from it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
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

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeInterface;
use OCA\Dossiq\Service\Advice\AdviceTimer;
use OCA\Dossiq\Service\Beschikking\BezwaarArchiveTimer;
use OCA\Dossiq\Service\Dso\DsoDeadlineTimer;
use OCA\Dossiq\Service\Milestone\MilestoneStallTimer;
use OCA\Dossiq\Service\Milestone\StalledCaseDetector;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\OpenRegister\Db\FlowTimer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The four engine timers: what they arm, and what they log when refused.
 *
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Term\DayCount
 */
class EngineTimerConfigurationTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The log calls the timers made, as [message, context] pairs.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * A settings mock resolving the given engine and answering config defaults.
	 *
	 * @param object|null           $engine   The engine, null for none.
	 * @param array<string, string> $settings Config values by key.
	 *
	 * @return SettingsService
	 */
	private function settings(?object $engine, array $settings = []): SettingsService {
		$mock = $this->createMock(SettingsService::class);
		$mock->method('getOpenRegisterClass')->willReturn($engine);
		$mock->method('getConfigValue')->willReturnCallback(static fn (string $key, string $default = ''): string => $settings[$key] ?? $default);
		return $mock;
	}//end settings()

	/**
	 * A logger that records error calls.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('error')->willReturnCallback(function (string $message, array $context = []): void {
			$this->logged[] = [$message, $context];
		});
		return $logger;
	}//end logger()

	/**
	 * An engine that cancels but refuses to arm.
	 *
	 * @return FlowTimerEngineFake
	 */
	private function armRefusingEngine(): FlowTimerEngineFake {
		return new class extends FlowTimerEngineFake {
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
				throw new RuntimeException('engine refuses arm');
			}
		};
	}//end armRefusingEngine()

	/**
	 * The engine fake's only armed configuration, with the anchor formatted.
	 *
	 * @param FlowTimerEngineFake $engine The engine fake.
	 *
	 * @return array<string, mixed>
	 */
	private function armedConfig(FlowTimerEngineFake $engine): array {
		$this->assertCount(1, $engine->calls['arm']);
		$config = $engine->calls['arm'][0]['config'];
		$this->assertNull($engine->calls['arm'][0]['actor']);
		$config['anchorEventAt'] = $config['anchorEventAt']->format('c');
		return $config;
	}//end armedConfig()

	/**
	 * One escalation rung as the timers write it.
	 *
	 * @param string $trigger  The trigger.
	 * @param int    $offset   The offset.
	 * @param string $unit     The offset unit.
	 * @param string $message  The message key.
	 * @param string $priority The priority.
	 *
	 * @return array<string, mixed>
	 */
	private static function rung(string $trigger, int $offset, string $unit, string $message, string $priority): array {
		return [
			'trigger'        => $trigger,
			'offset'         => $offset,
			'offsetUnit'     => $unit,
			'notifyRole'     => [],
			'escalateToRole' => [],
			'priority'       => $priority,
			'message'        => $message,
			'openIncident'   => false,
		];
	}//end rung()

	/**
	 * The advice timer.
	 *
	 * @param object|null $engine The engine.
	 *
	 * @return AdviceTimer
	 */
	private function adviceTimer(?object $engine): AdviceTimer {
		return new AdviceTimer(settingsService: $this->settings(engine: $engine), dates: $this->caseDatesFrozenAt(instant: '2026-10-10T14:00:00+02:00'), logger: $this->logger());
	}//end adviceTimer()

	/**
	 * The bezwaar archive timer.
	 *
	 * @param object|null $engine The engine.
	 *
	 * @return BezwaarArchiveTimer
	 */
	private function bezwaarTimer(?object $engine): BezwaarArchiveTimer {
		return new BezwaarArchiveTimer(settingsService: $this->settings(engine: $engine), dates: $this->caseDatesFrozenAt(instant: '2026-10-10T14:00:00+02:00'), logger: $this->logger());
	}//end bezwaarTimer()

	/**
	 * The DSO deadline timer.
	 *
	 * @param object|null $engine The engine.
	 *
	 * @return DsoDeadlineTimer
	 */
	private function dsoTimer(?object $engine): DsoDeadlineTimer {
		return new DsoDeadlineTimer(settingsService: $this->settings(engine: $engine), dates: $this->caseDatesFrozenAt(instant: '2026-10-10T14:00:00+02:00'), logger: $this->logger());
	}//end dsoTimer()

	/**
	 * The milestone stall timer over a fixed waiting row.
	 *
	 * @param object|null $engine The engine.
	 *
	 * @return MilestoneStallTimer
	 */
	private function milestoneTimer(?object $engine): MilestoneStallTimer {
		$detector = $this->createMock(StalledCaseDetector::class);
		$detector->method('waitingOn')->willReturn(['milestoneIdentifier' => 'documenten_compleet', 'milestoneLabel' => 'Documenten compleet', 'deadline' => '2026-10-20']);
		return new MilestoneStallTimer(settingsService: $this->settings(engine: $engine), detector: $detector, dates: $this->caseDatesFrozenAt(instant: '2026-10-10T14:00:00+02:00'), logger: $this->logger());
	}//end milestoneTimer()

	/**
	 * The advice timer's whole configuration, with its reminder rung.
	 *
	 * @return void
	 */
	public function testTheAdviceTimerConfiguration(): void {
		$engine = new FlowTimerEngineFake();
		$this->adviceTimer(engine: $engine)->sync(advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-20', 'subject' => 'Brandveiligheid', 'case' => 'case-9']);

		$this->assertSame([
				'subjectType'     => 'object',
				'subjectUuid'     => 'adv-1',
				'appId'           => 'dossiq',
				'title'           => 'Adviestermijn Brandveiligheid',
				'purpose'         => 'due',
				'legalEffect'     => 'none',
				'sla'             => ['value' => 11, 'unit' => 'calendarDays'],
				'escalationRules' => [
					self::rung(trigger: 'slaBreached', offset: 0, unit: 'calendarDays', message: 'advies-verlopen', priority: 'medium'),
					self::rung(trigger: 'preBreach', offset: 4, unit: 'calendarDays', message: 'advies-herinnering', priority: 'medium'),
				],
				'anchorEvent'     => 'advice_requested',
				'anchorEventAt'   => '2026-10-10T00:00:00+02:00',
				'metadata'        => ['source' => 'dossiq-advice', 'adviceId' => 'adv-1', 'caseId' => 'case-9'],
			],
			$this->armedConfig(engine: $engine)
		);
		$this->assertSame([['subjectType' => 'object', 'subjectUuid' => 'adv-1', 'reason' => 'Superseded by the current deadline', 'actor' => null]], $engine->calls['cancelForSubject']);
	}//end testTheAdviceTimerConfiguration()

	/**
	 * The bezwaar archive timer's whole configuration.
	 *
	 * @return void
	 */
	public function testTheBezwaarTimerConfiguration(): void {
		$engine = new FlowTimerEngineFake();
		$this->bezwaarTimer(engine: $engine)->sync(trigger: ['id' => 'trig-1', 'decisionId' => 'besch-1', 'archiveTriggerActive' => true, 'announcementDate' => '2026-10-01', 'archiveDate' => '2026-11-13']);

		$this->assertSame([
				'subjectType'     => 'object',
				'subjectUuid'     => 'trig-1',
				'appId'           => 'dossiq',
				'title'           => 'Bezwaartermijn besch-1',
				'purpose'         => 'due',
				'legalEffect'     => 'none',
				'sla'             => ['value' => 43, 'unit' => 'calendarDays'],
				'escalationRules' => [self::rung(trigger: 'slaBreached', offset: 0, unit: 'calendarDays', message: 'bezwaartermijn-verstreken', priority: 'normal')],
				'anchorEvent'     => 'bekendmaking',
				'anchorEventAt'   => '2026-10-01T00:00:00+02:00',
				'metadata'        => ['source' => 'dossiq-bezwaartermijn', 'triggerId' => 'trig-1', 'decisionId' => 'besch-1', 'basis' => 'Awb 6:7'],
			],
			$this->armedConfig(engine: $engine)
		);
		$this->assertSame([['subjectType' => 'object', 'subjectUuid' => 'trig-1', 'reason' => 'Superseded by the current bekendmaking', 'actor' => null]], $engine->calls['cancelForSubject']);
	}//end testTheBezwaarTimerConfiguration()

	/**
	 * The DSO deadline timer's whole configuration, with both lead rungs.
	 *
	 * @return void
	 */
	public function testTheDsoTimerConfiguration(): void {
		$engine = new FlowTimerEngineFake();
		$this->dsoTimer(engine: $engine)->sync(case: ['id' => 'case-1', 'identifier' => 'DSO-7', 'dsoStatus' => 'submitted', 'deadlineDate' => '2026-12-01']);

		$this->assertSame([
				'subjectType'     => 'object',
				'subjectUuid'     => 'case-1:dso',
				'appId'           => 'dossiq',
				'title'           => 'DSO-beslistermijn DSO-7',
				'purpose'         => 'due',
				'legalEffect'     => 'none',
				'sla'             => ['value' => 52, 'unit' => 'calendarDays'],
				'escalationRules' => [
					self::rung(trigger: 'slaBreached', offset: 0, unit: 'calendarDays', message: 'dso-termijn-overschreden', priority: 'normal'),
					self::rung(trigger: 'preBreach', offset: 14, unit: 'businessDays', message: 'dso-termijn-waarschuwing', priority: 'normal'),
					self::rung(trigger: 'preBreach', offset: 5, unit: 'businessDays', message: 'dso-termijn-kritiek', priority: 'normal'),
				],
				'anchorEvent'     => 'dso_term_running',
				'anchorEventAt'   => '2026-10-10T00:00:00+02:00',
				'metadata'        => ['source' => 'dossiq-dso', 'caseId' => 'case-1'],
			],
			$this->armedConfig(engine: $engine)
		);
		$this->assertSame([['subjectType' => 'object', 'subjectUuid' => 'case-1:dso', 'reason' => 'Superseded by the current DSO deadline', 'actor' => null]], $engine->calls['cancelForSubject']);
	}//end testTheDsoTimerConfiguration()

	/**
	 * The milestone stall timer's whole configuration.
	 *
	 * @return void
	 */
	public function testTheMilestoneTimerConfiguration(): void {
		$engine = new FlowTimerEngineFake();
		$this->milestoneTimer(engine: $engine)->sync(case: ['id' => 'case-1']);

		$this->assertSame([
				'subjectType'     => 'object',
				'subjectUuid'     => 'case-1:milestone',
				'appId'           => 'dossiq',
				'title'           => 'Mijlpaal Documenten compleet',
				'purpose'         => 'due',
				'legalEffect'     => 'none',
				'sla'             => ['value' => 11, 'unit' => 'calendarDays'],
				'escalationRules' => [self::rung(trigger: 'slaBreached', offset: 0, unit: 'calendarDays', message: 'mijlpaal-vertraagd', priority: 'normal')],
				'anchorEvent'     => 'milestone_waiting',
				'anchorEventAt'   => '2026-10-10T00:00:00+02:00',
				'metadata'        => ['source' => 'dossiq-milestone', 'caseId' => 'case-1', 'milestoneIdentifier' => 'documenten_compleet'],
			],
			$this->armedConfig(engine: $engine)
		);
		$this->assertSame([['subjectType' => 'object', 'subjectUuid' => 'case-1:milestone', 'reason' => 'Superseded by the milestone the case waits on now', 'actor' => null]], $engine->calls['cancelForSubject']);
	}//end testTheMilestoneTimerConfiguration()

	/**
	 * Each timer logs its own sentence when the engine refuses to arm.
	 *
	 * @return void
	 */
	public function testARefusedArmLogsTheTimersOwnSentence(): void {
		$this->assertSame(AdviceTimer::UNAVAILABLE, $this->adviceTimer(engine: $this->armRefusingEngine())->sync(advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-20']));
		$this->assertSame(BezwaarArchiveTimer::UNAVAILABLE, $this->bezwaarTimer(engine: $this->armRefusingEngine())->sync(trigger: ['id' => 'trig-1', 'archiveTriggerActive' => true, 'archiveDate' => '2026-11-13']));
		$this->assertSame(DsoDeadlineTimer::UNAVAILABLE, $this->dsoTimer(engine: $this->armRefusingEngine())->sync(case: ['id' => 'case-1', 'dsoStatus' => 'submitted', 'deadlineDate' => '2026-12-01']));
		$this->assertSame(MilestoneStallTimer::UNAVAILABLE, $this->milestoneTimer(engine: $this->armRefusingEngine())->sync(case: ['id' => 'case-1']));

		$this->assertSame([
				['Dossiq advice: the engine refused the advice timer', ['advice' => 'adv-1', 'error' => 'engine refuses arm']],
				['Dossiq bezwaar: the engine refused the bezwaartermijn timer', ['trigger' => 'trig-1', 'error' => 'engine refuses arm']],
				['Dossiq DSO: the engine refused the DSO term timer', ['case' => 'case-1', 'error' => 'engine refuses arm']],
				['Dossiq milestone: the engine refused the stall timer', ['case' => 'case-1', 'error' => 'engine refuses arm']],
			],
			$this->logged
		);
	}//end testARefusedArmLogsTheTimersOwnSentence()

	/**
	 * Each timer logs its own sentence when the engine refuses to cancel.
	 *
	 * @return void
	 */
	public function testARefusedCancelLogsTheTimersOwnSentence(): void {
		$refusing         = new FlowTimerEngineFake();
		$refusing->refuse = true;

		$this->assertSame(AdviceTimer::UNAVAILABLE, $this->adviceTimer(engine: $refusing)->sync(advice: ['id' => 'adv-1', 'status' => 'received']));
		$this->assertSame(BezwaarArchiveTimer::UNAVAILABLE, $this->bezwaarTimer(engine: $refusing)->sync(trigger: ['id' => 'trig-1']));
		$this->assertSame(DsoDeadlineTimer::UNAVAILABLE, $this->dsoTimer(engine: $refusing)->sync(case: ['id' => 'case-1']));
		$this->assertSame(MilestoneStallTimer::UNAVAILABLE, $this->milestoneTimer(engine: $refusing)->sync(case: ['id' => 'case-1']));

		$this->assertSame([
				['Dossiq advice: the engine refused to cancel the advice timer', ['advice' => 'adv-1', 'error' => 'engine refuses cancelForSubject']],
				['Dossiq bezwaar: the engine refused to cancel the bezwaartermijn timer', ['trigger' => 'trig-1', 'error' => 'engine refuses cancelForSubject']],
				['Dossiq DSO: the engine refused to cancel the DSO term timer', ['case' => 'case-1', 'error' => 'engine refuses cancelForSubject']],
				['Dossiq milestone: the engine refused to cancel the stall timer', ['case' => 'case-1', 'error' => 'engine refuses cancelForSubject']],
			],
			$this->logged
		);
	}//end testARefusedCancelLogsTheTimersOwnSentence()

	/**
	 * Without the engine nothing is logged and every timer is unavailable.
	 *
	 * @return void
	 */
	public function testAnAbsentEngineLogsNothing(): void {
		$this->assertSame(AdviceTimer::UNAVAILABLE, $this->adviceTimer(engine: null)->sync(advice: ['id' => 'adv-1', 'status' => 'requested', 'deadline' => '2026-10-20']));
		$this->assertSame(BezwaarArchiveTimer::UNAVAILABLE, $this->bezwaarTimer(engine: null)->sync(trigger: ['id' => 'trig-1']));
		$this->assertSame(DsoDeadlineTimer::UNAVAILABLE, $this->dsoTimer(engine: null)->sync(case: ['id' => 'case-1']));
		$this->assertSame(MilestoneStallTimer::UNAVAILABLE, $this->milestoneTimer(engine: null)->sync(case: ['id' => 'case-1']));
		$this->assertSame([], $this->logged);
	}//end testAnAbsentEngineLogsNothing()
}//end class
