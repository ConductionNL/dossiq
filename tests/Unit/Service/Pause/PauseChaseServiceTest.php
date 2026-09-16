<?php

/**
 * A reminder goes out, gets counted, and stops when the case stops waiting.
 *
 * The three things worth pinning: the reminder leaves by the one outbound
 * route and is recorded on the term AND on the case timeline; a rung that
 * arrives after the term resumed sends nothing; and a send that throws counts
 * nothing, so the budget is not spent on a letter nobody received.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Pause
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pause;

use DateTimeImmutable;
use OCA\Dossiq\Service\AanvullingsverzoekService;
use OCA\Dossiq\Service\Pause\ChaseSchedule;
use OCA\Dossiq\Service\Pause\PauseChaseService;
use OCA\Dossiq\Service\Pause\PauseReason;
use OCA\Dossiq\Service\Pause\PauseReasonReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\Timeline\TimelineKinds;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * REQ-TERM-011: each reminder is sent, recorded and counted exactly once.
 *
 * @covers \OCA\Dossiq\Service\Pause\PauseChaseService
 */
class PauseChaseServiceTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The term store.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService $termService;

	/**
	 * The one outbound route a citizen letter leaves by.
	 *
	 * @var TermijnNotificationService&MockObject
	 */
	private TermijnNotificationService $notifications;

	/**
	 * The one log a handler reads.
	 *
	 * @var CaseTimeline&MockObject
	 */
	private CaseTimeline $timeline;

	/**
	 * What the case type declared.
	 *
	 * @var PauseReasonReader&MockObject
	 */
	private PauseReasonReader $reasons;

	/**
	 * The open request, which carries the address.
	 *
	 * @var AanvullingsverzoekService&MockObject
	 */
	private AanvullingsverzoekService $aanvullingen;

	/**
	 * The patches the service wrote onto the instance.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $patches = [];

	/**
	 * The events the service recorded.
	 *
	 * @var array<int, string>
	 */
	private array $events = [];

	/**
	 * Wire the service against doubles of everything it writes through.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->termService = $this->createMock(TermijnService::class);
		$this->notifications = $this->createMock(TermijnNotificationService::class);
		$this->timeline = $this->createMock(CaseTimeline::class);
		$this->reasons = $this->createMock(PauseReasonReader::class);
		$this->aanvullingen = $this->createMock(AanvullingsverzoekService::class);

		$this->patches = [];
		$this->events = [];

		$this->termService->method('updateTermijnInstance')->willReturnCallback(
			function (string $id, array $patch): array {
				$this->patches[] = $patch;
				return array_merge(['id' => $id], $patch);
			}
		);
		$this->termService->method('recordEvent')->willReturnCallback(
			function (string $instanceId, string $type): array {
				$this->events[] = $type;
				return ['deadlineInstance' => $instanceId, 'type' => $type];
			}
		);

		$this->aanvullingen->method('openFor')->willReturn(['recipient' => 'aanvrager@example.org']);
		$this->reasons->method('caseTypeForCase')->willReturn($this->caseType());
		$this->reasons->method('reasonsIn')->willReturn([$this->reason()]);
	}//end setUp()

	/**
	 * The service, wired.
	 *
	 * @return PauseChaseService The service under test.
	 */
	private function service(): PauseChaseService {
		return new PauseChaseService(
			termService: $this->termService,
			reasons: $this->reasons,
			schedule: new ChaseSchedule(
				calendar: new WorkingDayCalculator(),
				dates: $this->caseDates(),
			),
			notifications: $this->notifications,
			timeline: $this->timeline,
			settings: $this->createMock(SettingsService::class),
			logger: new NullLogger(),
			aanvullingen: $this->aanvullingen,
		);
	}//end service()

	/**
	 * The declared reason these cases run under.
	 *
	 * @return array<string, mixed> The normalised reason.
	 */
	private function reason(): array {
		return PauseReason::normalise(
			row: [
				'key' => 'aanvulling-aanvrager',
				'name' => 'Aanvulling gevraagd',
				'category' => 'applicant',
				'legalBasis' => 'Awb 4:5',
				'chaseIntervalDays' => 5,
				'chaseBudget' => 2,
				'countsWorkingDays' => false,
				'chaseText' => 'Wij hebben uw aanvulling nog niet ontvangen.',
				'escalateTo' => 'handler',
			]
		);
	}//end reason()

	/**
	 * The case type row behind these cases: it sends every message.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function caseType(): array {
		return ['handling' => ['automaticMessages' => [PauseChaseService::TEMPLATE]]];
	}//end caseType()

	/**
	 * One suspended instance, as the store answers it.
	 *
	 * @param array<string, mixed> $overrides What this case needs different.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function instance(array $overrides = []): array {
		return array_merge(
			[
				'id' => 't1',
				'case' => 'c1',
				'status' => 'paused',
				'pauzeStartDatum' => '2026-09-01',
				'pauseDeadline' => '2026-09-15',
				'pauseReason' => 'aanvulling-aanvrager',
				'chasesSent' => 0,
			],
			$overrides
		);
	}//end instance()

	/**
	 * The first reminder is sent, recorded on the term and on the timeline, and
	 * counted.
	 *
	 * @return void
	 */
	public function testTheFirstReminderIsSentRecordedAndCounted(): void {
		$this->termService->method('getTermijnInstance')->willReturn($this->instance());

		$this->notifications->expects($this->once())
			->method('sendTermijnNotification')
			->with(
				PauseChaseService::TEMPLATE,
				't1',
				'aanvrager@example.org',
				$this->callback(
					static fn (array $context): bool
						=> $context['chaseText'] === 'Wij hebben uw aanvulling nog niet ontvangen.'
				)
			)
			->willReturn([]);

		$this->timeline->expects($this->once())
			->method('record')
			->with(
				'c1',
				TimelineKinds::TERM_EVENT,
				$this->stringContains('Reminder 1 of 2'),
				$this->callback(
					static fn (array $fields): bool => $fields['event'] === PauseChaseService::EVENT_CHASED
				)
			)
			->willReturn('entry-1');

		$sent = $this->service()->chaseIfDue(
			instance: $this->instance(),
			now: new DateTimeImmutable('2026-09-06')
		);

		$this->assertTrue($sent);
		$this->assertSame([PauseChaseService::EVENT_CHASED], $this->events);
		$this->assertSame(1, $this->patches[0]['chasesSent']);
		$this->assertNotSame('', (string)$this->patches[0]['lastChasedAt']);
	}//end testTheFirstReminderIsSentRecordedAndCounted()

	/**
	 * A rung that fires after the term resumed sends nothing. The engine cannot
	 * cancel one timer, so this guard is what drops a late fire.
	 *
	 * @return void
	 */
	public function testAReminderAfterTheTermResumedSendsNothing(): void {
		$this->termService->method('getTermijnInstance')->willReturn($this->instance(['status' => 'lopend']));
		$this->notifications->expects($this->never())->method('sendTermijnNotification');
		$this->timeline->expects($this->never())->method('record');

		$this->assertFalse(
			$this->service()->chaseIfDue(
				instance: $this->instance(),
				now: new DateTimeImmutable('2026-09-06')
			)
		);
		$this->assertSame([], $this->events);
		$this->assertSame([], $this->patches);
	}//end testAReminderAfterTheTermResumedSendsNothing()

	/**
	 * The instance is re-read, so a trigger carrying a stale row finds the
	 * reminder its predecessor already sent and sends nothing.
	 *
	 * @return void
	 */
	public function testASecondTriggerOnAStaleRowSendsNothing(): void {
		$this->termService->method('getTermijnInstance')->willReturn(
			$this->instance(['chasesSent' => 1, 'lastChasedAt' => '2026-09-06T09:00:00+02:00'])
		);
		$this->notifications->expects($this->never())->method('sendTermijnNotification');

		$this->assertFalse(
			$this->service()->chaseIfDue(
				instance: $this->instance(),
				now: new DateTimeImmutable('2026-09-06')
			)
		);
	}//end testASecondTriggerOnAStaleRowSendsNothing()

	/**
	 * A send that failed is not a reminder: nothing is counted, nothing is
	 * recorded, and the next run tries again.
	 *
	 * @return void
	 */
	public function testAFailedSendCountsNothing(): void {
		$this->termService->method('getTermijnInstance')->willReturn($this->instance());
		$this->notifications->method('sendTermijnNotification')
			->willThrowException(new RuntimeException('the berichtenbox is down'));
		$this->timeline->expects($this->never())->method('record');

		$this->assertFalse(
			$this->service()->chaseIfDue(
				instance: $this->instance(),
				now: new DateTimeImmutable('2026-09-06')
			)
		);
		$this->assertSame([], $this->events);
		$this->assertSame([], $this->patches);
	}//end testAFailedSendCountsNothing()

	/**
	 * A case with no address recorded is not chased, and the budget is not
	 * spent on a letter that had nowhere to go.
	 *
	 * @return void
	 */
	public function testACaseWithNoRecordedAddressIsNotChased(): void {
		$this->termService->method('getTermijnInstance')->willReturn($this->instance());
		$this->aanvullingen = $this->createMock(AanvullingsverzoekService::class);
		$this->aanvullingen->method('openFor')->willReturn(['recipient' => '']);
		$this->notifications->expects($this->never())->method('sendTermijnNotification');

		$this->assertFalse(
			$this->service()->chaseIfDue(
				instance: $this->instance(),
				now: new DateTimeImmutable('2026-09-06')
			)
		);
	}//end testACaseWithNoRecordedAddressIsNotChased()

	/**
	 * After the last reminder in the budget, the handler hears that nobody
	 * replied, and hears it once.
	 *
	 * @return void
	 */
	public function testTheHandlerHearsAfterTheLastReminder(): void {
		$this->termService->method('getTermijnInstance')->willReturn(
			$this->instance(['chasesSent' => 2, 'lastChasedAt' => '2026-09-11T09:00:00+02:00'])
		);

		$this->timeline->expects($this->once())
			->method('record')
			->with(
				'c1',
				TimelineKinds::TERM_EVENT,
				$this->stringContains('No reply after 2 reminders'),
				$this->callback(
					static fn (array $fields): bool => $fields['event'] === PauseChaseService::EVENT_ESCALATED
				)
			)
			->willReturn('entry-2');

		$escalated = $this->service()->escalateIfDue(
			instance: $this->instance(),
			now: new DateTimeImmutable('2026-09-16')
		);

		$this->assertTrue($escalated);
		$this->assertSame([PauseChaseService::EVENT_ESCALATED], $this->events);
		$this->assertNotSame('', (string)$this->patches[0]['chaseEscalatedAt']);
	}//end testTheHandlerHearsAfterTheLastReminder()

	/**
	 * A case type that does not send the reminder is not chased, however the
	 * reason is declared. The message switch is an administrator's decision.
	 *
	 * @return void
	 */
	public function testACaseTypeThatDoesNotSendTheReminderIsNotChased(): void {
		$this->termService->method('getTermijnInstance')->willReturn($this->instance());
		$this->reasons = $this->createMock(PauseReasonReader::class);
		$this->reasons->method('caseTypeForCase')->willReturn(
			['handling' => ['automaticMessages' => ['ontvangstbevestiging']]]
		);
		$this->reasons->method('reasonsIn')->willReturn([$this->reason()]);
		$this->notifications->expects($this->never())->method('sendTermijnNotification');

		$this->assertFalse(
			$this->service()->chaseIfDue(
				instance: $this->instance(),
				now: new DateTimeImmutable('2026-09-06')
			)
		);
	}//end testACaseTypeThatDoesNotSendTheReminderIsNotChased()

	/**
	 * A pause on a case type that declares no reason is left alone entirely.
	 *
	 * @return void
	 */
	public function testAPauseWithNoDeclaredReasonIsLeftAlone(): void {
		$this->termService->method('getTermijnInstance')->willReturn($this->instance());
		$this->reasons = $this->createMock(PauseReasonReader::class);
		$this->reasons->method('caseTypeForCase')->willReturn($this->caseType());
		$this->reasons->method('reasonsIn')->willReturn([]);
		$this->notifications->expects($this->never())->method('sendTermijnNotification');

		$this->assertFalse(
			$this->service()->chaseIfDue(
				instance: $this->instance(),
				now: new DateTimeImmutable('2026-10-01')
			)
		);
	}//end testAPauseWithNoDeclaredReasonIsLeftAlone()
}//end class
