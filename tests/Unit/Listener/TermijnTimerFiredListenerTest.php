<?php

/**
 * Unit tests for TermijnTimerFiredListener.
 *
 * Drives real engine-shaped FlowTimerFiredEvent objects (via the stubs
 * that mirror the engine's constructor) through the listener against the
 * in-memory store, asserting the domain side: threshold bookkeeping with
 * `notificatiesVerstuurd` dedup, the breach flip to `exceeded`, the
 * still-paused guard on the hersteltermijn helper, the dwangsom accrual
 * sync, and the ownership filters.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/termijnbewaking-op-engine-timers/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use OCA\Dossiq\Listener\TermijnTimerFiredListener;
use OCA\Dossiq\Service\CaseDateNormaliser;
use OCA\Dossiq\Service\CasePriorityRaiseService;
use OCA\Dossiq\Service\DeadlineEscalationService;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\Deelzaak\CaseObjectReader;
use OCA\Dossiq\Service\ServiceAccount\BackgroundServiceAccount;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Tests\Unit\Service\FakeTermijnStore;
use OCA\OpenRegister\Db\FlowTimer;
use OCA\OpenRegister\Event\FlowTimerFiredEvent;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\OpenRegister\Service\Flow\Timer\EscalationNotice;
use OCA\OpenRegister\Service\Flow\Timer\TimerFiring;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Listener\TermijnTimerFiredListener
 *
 * @uses \OCA\Dossiq\Service\DeadlineEscalationService
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Service\TermijnService
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Termijn\TermDefinitions
 * @uses \OCA\Dossiq\Service\Termijn\TermInstanceStore
 * @uses \OCA\Dossiq\Listener\Support\TimerFire
 * @uses \OCA\Dossiq\Service\DeadlinePauseService
 * @uses \OCA\Dossiq\Service\Term\DayCount
 */
class TermijnTimerFiredListenerTest extends TestCase {
	use MakesCaseDateNormaliser;

	private FakeTermijnStore $objects;
	private TermijnService $termService;
	private TermijnTimerFiredListener $listener;

	/**
	 * The logger every listener in this test writes to.
	 *
	 * @var LoggerInterface
	 */
	private LoggerInterface $logger;

	/**
	 * The background account every listener in this test runs as.
	 *
	 * @var BackgroundServiceAccount
	 */
	private BackgroundServiceAccount $account;

	/**
	 * The one clock the accrual and the fixtures below both read.
	 *
	 * @var CaseDateNormaliser
	 */
	private CaseDateNormaliser $dates;

	protected function setUp(): void {
		$this->dates = $this->caseDatesFrozenAt();
		$this->objects = new FakeTermijnStore();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key): string {
				return match ($key) {
					'register' => 'dossiq',
					'termijn_definitie_schema' => 'deadlineDefinition',
					'termijn_instance_schema' => 'deadlineInstance',
					'termijn_gebeurtenis_schema' => 'termijnGebeurtenis',
					'accruing_amount_schema' => 'penaltyPaymentCalculation',
					default => '',
				};
			},
		);

		$logger = $this->createMock(LoggerInterface::class);
		$this->termService = new TermijnService($settings, $logger);
		// These tests are about the term, not the writer: the account runs the
		// fire as it comes. TermijnTimerFiredListenerServiceAccountTest covers who writes.
		$account = $this->createMock(BackgroundServiceAccount::class);
		$account->method('runAsWhenNobodyIsSignedIn')->willReturnCallback(static fn (callable $operation): mixed => $operation());
		$this->logger = $logger;
		$this->account = $account;
		$this->listener = new TermijnTimerFiredListener(
			$this->termService,
			new DeadlineEscalationService(
				termService: $this->termService,
				priorityRaiseService: $this->createMock(
					originalClassName: CasePriorityRaiseService::class
				),
				logger: $logger
			),
			$logger,
			$account
		);
	}

	/**
	 * Seed one instance, returning its id.
	 *
	 * @param array<string, mixed> $overrides Field overrides.
	 *
	 * @return string
	 */
	private function seedInstance(array $overrides = []): string {
		$row = $this->objects->seed('deadlineInstance', array_merge([
			'case' => 'Z/2026/L1',
			'deadlineDefinition' => 'td-ov',
			'startDate' => '2026-06-01T10:00:00+00:00',
			'endDateCalculated' => '2026-07-27',
			'endDateCurrent' => '2026-07-27',
			'status' => 'lopend',
			'notificatiesVerstuurd' => [],
			'engineTimerId' => 'timer-1',
		], $overrides));

		return (string)$row['id'];
	}

	/**
	 * Build an engine-shaped rung fire for a dossiq termijn timer.
	 *
	 * @param string $instanceId The bound instance.
	 * @param string $rungKey The rung's stable key.
	 * @param string $kind The metadata kind.
	 * @param string $appId The arming app.
	 *
	 * @return FlowTimerFiredEvent
	 */
	private function rungFire(
		string $instanceId,
		string $rungKey,
		string $kind = 'beslistermijn',
		string $appId = 'dossiq',
	): FlowTimerFiredEvent {
		$timer = new FlowTimer();
		$timer->setUuid('timer-1');
		$timer->setAppId($appId);
		$timer->setMetadata([
			'source' => 'dossiq-termijn',
			'kind' => $kind,
			'termijnInstanceId' => $instanceId,
			'caseId' => 'Z/2026/L1',
			'basis' => 'AWB 4:13',
		]);

		return new FlowTimerFiredEvent(
			timer: $timer,
			firing: new TimerFiring(kind: FlowTimerFiredEvent::KIND_RUNG, transition: 'escalation:' . $rungKey, rungKey: $rungKey),
			notice: new EscalationNotice(recipients: [['type' => 'user', 'id' => 'handler-1', 'role' => 'handler']], priority: 'medium'),
		);
	}

	/**
	 * The recorded gebeurtenis types for an instance.
	 *
	 * @param string $instanceId The instance.
	 *
	 * @return array<int, string>
	 */
	private function eventTypes(string $instanceId): array {
		$types = [];
		foreach (($this->objects->store['termijnGebeurtenis'] ?? []) as $row) {
			if ((string)($row['deadlineInstance'] ?? '') === $instanceId) {
				$types[] = (string)($row['type'] ?? '');
			}
		}

		return $types;
	}

	/**
	 * FIXTURE PAIR (thresholds == ladder rungs): the engine's
	 * `preBreach:7:calendarDays` rung lands in the same bucket 7 the
	 * retired scan computed, and the notificatiesVerstuurd bookkeeping is
	 * byte-identical to the scan era.
	 *
	 * @return void
	 */
	public function testPreBreachRungDispatchesItsThresholdOnce(): void {
		$id = $this->seedInstance();

		$this->listener->handle($this->rungFire($id, 'preBreach:7:calendarDays'));
		$after = $this->termService->getTermijnInstance($id);
		self::assertSame([7], $after['notificatiesVerstuurd']);

		// The same rung again (or a second sweep pass): no duplicate.
		$this->listener->handle($this->rungFire($id, 'preBreach:7:calendarDays'));
		$again = $this->termService->getTermijnInstance($id);
		self::assertSame([7], $again['notificatiesVerstuurd']);
	}

	/**
	 * The breach rung flips the instance and records the exceeded event —
	 * the exact writes the retired scan performed.
	 *
	 * @return void
	 */
	public function testBreachRungFlipsInstanceToExceeded(): void {
		$id = $this->seedInstance();

		$this->listener->handle($this->rungFire($id, 'slaBreached:0'));

		$after = $this->termService->getTermijnInstance($id);
		self::assertSame('exceeded', $after['status']);
		self::assertContains(0, $after['notificatiesVerstuurd']);
		self::assertContains('exceeded', $this->eventTypes($id));
	}

	/**
	 * A breach rung on an already-exceeded instance records nothing twice.
	 *
	 * @return void
	 */
	public function testBreachRungIsIdempotent(): void {
		$id = $this->seedInstance();

		$this->listener->handle($this->rungFire($id, 'slaBreached:0'));
		$this->listener->handle($this->rungFire($id, 'slaBreached:0'));

		$types = array_count_values($this->eventTypes($id));
		self::assertSame(1, $types['exceeded']);
	}

	/**
	 * A fire leaves the penalty calculation alone: its day count and amount
	 * are stored calculations OpenRegister keeps current, so the listener no
	 * longer writes them (ADR-118 C16).
	 *
	 * @return void
	 */
	public function testFireLeavesThePenaltyCalculationToOpenRegister(): void {
		$id = $this->seedInstance(['status' => 'exceeded']);
		$row = [
			'id' => 'b-l1',
			'deadlineInstance' => $id,
			'startDate' => $this->dates->today()->modify('-5 days')->format('Y-m-d'),
			'plafondCalculated' => 144200,
			'status' => 'lopend',
			'regime' => 'awb-default',
		];
		$this->objects->seed('penaltyPaymentCalculation', $row);

		$this->listener->handle($this->rungFire($id, 'slaBreached:0'));

		self::assertSame($row, $this->objects->store['penaltyPaymentCalculation']['b-l1']);
	}

	/**
	 * The hersteltermijn helper records pause-expired only while the
	 * instance is still paused (REQ-TOT-002).
	 *
	 * @return void
	 */
	public function testPauseExpiryFiresOnlyWhileStillPaused(): void {
		$paused = $this->seedInstance(['status' => 'paused', 'pauseDeadline' => '2026-06-15']);
		$this->listener->handle($this->rungFire($paused, 'slaBreached:0', kind: 'hersteltermijn'));
		self::assertContains('pause-expired', $this->eventTypes($paused));

		// A helper outliving an on-time aanvulling fires late; the guard drops it.
		$resumed = $this->seedInstance(['status' => 'lopend']);
		$this->listener->handle($this->rungFire($resumed, 'slaBreached:0', kind: 'hersteltermijn'));
		self::assertNotContains('pause-expired', $this->eventTypes($resumed));
	}

	/**
	 * A pause that runs out resumes the term by itself, with the full pause
	 * kept on the end date, and the hervat event tells the case's handler the
	 * case and the new end date (REQ-TERM-080).
	 *
	 * @return void
	 */
	public function testAnExpiredPauseResumesTheTermAndTellsTheHandler(): void {
		$cases = $this->createMock(CaseObjectReader::class);
		$cases->expects(self::once())->method('fetchCaseById')->with('Z/2026/L1')
			->willReturn(['title' => 'Melding Jeugdwet Oosthaven', 'identifier' => 'ZAAK-1', 'assignee' => 'pieter']);
		$listener = $this->resumingListener(cases: $cases);
		$id = $this->seedInstance(['status' => 'paused', 'endDateCurrent' => '2026-08-10', 'pauseDeadline' => '2026-07-15', 'pauseReason' => 'aanvulling', 'chasesSent' => 2]);

		$listener->handle($this->rungFire($id, 'slaBreached:0', kind: 'hersteltermijn'));

		$instance = $this->objects->store['deadlineInstance'][$id];
		self::assertSame('lopend', $instance['status']);
		self::assertSame('2026-08-10', $instance['endDateCurrent']);
		self::assertNull($instance['pauseDeadline']);
		self::assertSame('', $instance['pauseReason']);
		self::assertSame(0, $instance['chasesSent']);
		self::assertSame(['pause-expired', 'hervat'], $this->eventTypes($id));

		$hervat = $this->eventsOf($id)[1];
		self::assertSame(DeadlinePauseService::EXPIRED_RESUME_REASON, $hervat['rationale']);
		self::assertSame('AWB 4:15', $hervat['basis']);
		self::assertSame(0, $hervat['daysImpact']);
		self::assertSame('pieter', $hervat['notify']);
		self::assertSame('Melding Jeugdwet Oosthaven', $hervat['caseTitle']);
		self::assertSame('2026-08-10', $hervat['endDate']);
		self::assertArrayNotHasKey('notify', $this->eventsOf($id)[0], 'the expiry itself tells nobody');
	}//end testAnExpiredPauseResumesTheTermAndTellsTheHandler()

	/**
	 * A term the handler resumed before the pause ran out is not resumed a
	 * second time, and nobody is told (REQ-TERM-080, early resume).
	 *
	 * @return void
	 */
	public function testAHandlerResumeBeforeTheEndLeavesNoSecondResume(): void {
		$cases = $this->createMock(CaseObjectReader::class);
		$cases->expects(self::never())->method('fetchCaseById');
		$listener = $this->resumingListener(cases: $cases);
		$id = $this->seedInstance(['status' => 'lopend', 'endDateCurrent' => '2026-08-04']);

		$listener->handle($this->rungFire($id, 'slaBreached:0', kind: 'hersteltermijn'));

		self::assertSame([], $this->eventTypes($id));
		self::assertSame('2026-08-04', $this->objects->store['deadlineInstance'][$id]['endDateCurrent']);
	}//end testAHandlerResumeBeforeTheEndLeavesNoSecondResume()

	/**
	 * A case without a handler, or one that cannot be read, still resumes;
	 * the event then carries no notice, so the rule tells nobody.
	 *
	 * @return void
	 */
	public function testACaseWithoutAHandlerResumesAndTellsNobody(): void {
		$cases = $this->createMock(CaseObjectReader::class);
		$cases->method('fetchCaseById')->willReturnOnConsecutiveCalls(['identifier' => 'ZAAK-2', 'assignee' => ''], null);
		$listener = $this->resumingListener(cases: $cases);

		$unassigned = $this->seedInstance(['status' => 'paused', 'endDateCurrent' => '2026-08-10']);
		$listener->handle($this->rungFire($unassigned, 'slaBreached:0', kind: 'hersteltermijn'));
		$unreadable = $this->seedInstance(['status' => 'paused', 'endDateCurrent' => '2026-08-11']);
		$listener->handle($this->rungFire($unreadable, 'slaBreached:0', kind: 'hersteltermijn'));

		$first = $this->eventsOf($unassigned)[1];
		self::assertSame('lopend', $this->objects->store['deadlineInstance'][$unassigned]['status']);
		self::assertArrayNotHasKey('notify', $first);
		self::assertSame('ZAAK-2', $first['caseTitle'], 'a case without a title is named by its identifier');
		self::assertSame('2026-08-10', $first['endDate']);

		$second = $this->eventsOf($unreadable)[1];
		self::assertSame('lopend', $this->objects->store['deadlineInstance'][$unreadable]['status']);
		self::assertArrayNotHasKey('notify', $second);
		self::assertArrayNotHasKey('caseTitle', $second);
		self::assertSame('2026-08-11', $second['endDate']);
	}//end testACaseWithoutAHandlerResumesAndTellsNobody()

	/**
	 * A resume that fails is logged and does not escape the listener: the
	 * expiry is still recorded and the engine sweep is not poisoned.
	 *
	 * @return void
	 */
	public function testAFailedResumeIsLoggedAndContained(): void {
		$pauses = $this->createMock(DeadlinePauseService::class);
		$pauses->method('resumeAfterExpiry')->willThrowException(new \RuntimeException('store down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(
			'Dossiq termijn: a term whose pause ran out could not be resumed',
			self::callback(static fn (array $context): bool => $context['error'] === 'store down')
		);
		$listener = new TermijnTimerFiredListener(
			$this->termService,
			$this->createMock(DeadlineEscalationService::class),
			$logger,
			$this->account,
			null,
			null,
			$pauses,
			null
		);
		$id = $this->seedInstance(['status' => 'paused']);

		$listener->handle($this->rungFire($id, 'slaBreached:0', kind: 'hersteltermijn'));

		self::assertSame(['pause-expired'], $this->eventTypes($id));
		self::assertSame('paused', $this->objects->store['deadlineInstance'][$id]['status']);
	}//end testAFailedResumeIsLoggedAndContained()

	/**
	 * The listener with the pause service over this test's term store.
	 *
	 * @param CaseObjectReader $cases The case reader.
	 *
	 * @return TermijnTimerFiredListener
	 */
	private function resumingListener(CaseObjectReader $cases): TermijnTimerFiredListener {
		return new TermijnTimerFiredListener(
			$this->termService,
			$this->createMock(DeadlineEscalationService::class),
			$this->logger,
			$this->account,
			null,
			null,
			new DeadlinePauseService(termService: $this->termService),
			$cases
		);
	}//end resumingListener()

	/**
	 * The recorded gebeurtenis rows for an instance, in order.
	 *
	 * @param string $instanceId The instance.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function eventsOf(string $instanceId): array {
		$rows = [];
		foreach (($this->objects->store['termijnGebeurtenis'] ?? []) as $row) {
			if ((string)($row['deadlineInstance'] ?? '') === $instanceId) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end eventsOf()

	/**
	 * Fires from other apps or foreign metadata are not ours.
	 *
	 * @return void
	 */
	public function testForeignTimersAreIgnored(): void {
		$id = $this->seedInstance();

		$this->listener->handle($this->rungFire($id, 'preBreach:7:calendarDays', appId: 'someotherapp'));

		$after = $this->termService->getTermijnInstance($id);
		self::assertSame([], $after['notificatiesVerstuurd']);
	}

	/**
	 * A rung reaching a completed term does nothing: the subject is
	 * terminal and its timers should already be cancelled.
	 *
	 * @return void
	 */
	public function testTerminalInstancesAreLeftAlone(): void {
		$id = $this->seedInstance(['status' => 'completed']);

		$this->listener->handle($this->rungFire($id, 'slaBreached:0'));

		$after = $this->termService->getTermijnInstance($id);
		self::assertSame('completed', $after['status']);
		self::assertSame([], $this->eventTypes($id));
	}
}
