<?php

/**
 * The nightly sweep, the waiting sentence on the case, and the edges of a chase.
 *
 * PauseChaseServiceTest pins one reminder end to end. This suite pins what
 * surrounds it: the sweep asks OpenRegister for suspended terms only and counts
 * what it chased and escalated; a sweep that cannot ask says so instead of
 * reporting a quiet night; the case gets the waiting facts after a reminder,
 * and a failure there does not undo the reminder; and the rows that are not
 * chaseable (no id, no case, no recipient service, an unreadable request) send
 * nothing.
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
use OCA\Dossiq\Service\Pause\PauseChaseSources;
use OCA\Dossiq\Service\Pause\PauseReason;
use OCA\Dossiq\Service\Pause\PauseReasonReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnNotificationService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Timeline\CaseTimeline;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * REQ-TERM-011: the sweep, the case projection and the unchaseable rows.
 *
 * @covers \OCA\Dossiq\Service\Pause\PauseChaseService
 * @covers \OCA\Dossiq\Service\Pause\PauseChaseSources
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 * @uses \OCA\Dossiq\Service\Pause\ChaseSchedule
 * @uses \OCA\Dossiq\Service\Pause\PauseReason
 * @uses \OCA\Dossiq\Service\Pause\PauseStep
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 */
class PauseChaseServiceSweepTest extends TestCase {
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
	 * The OpenRegister seam.
	 *
	 * @var SettingsService&MockObject
	 */
	private SettingsService $settings;

	/**
	 * A stand-in for OpenRegister's ObjectService that records what it was asked.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * The events the service recorded, as [instance, type, basis, rationale].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $events = [];

	/**
	 * Wire the doubles every test starts from.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->termService = $this->createMock(TermijnService::class);
		$this->notifications = $this->createMock(TermijnNotificationService::class);
		$this->timeline = $this->createMock(CaseTimeline::class);
		$this->reasons = $this->createMock(PauseReasonReader::class);
		$this->aanvullingen = $this->createMock(AanvullingsverzoekService::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->events = [];

		$this->objects = new class {
			/** @var array<int, array<int, mixed>> */
			public array $searches = [];
			/** @var array<int, array<string, mixed>> */
			public array $patches = [];
			/** @var array<int, array<string, mixed>> */
			public array $rows = [];
			public ?\Throwable $searchFails = null;
			public ?\Throwable $patchFails = null;

			public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
				$this->searches[] = [$register, $schema, $filters];
				if ($this->searchFails !== null) {
					throw $this->searchFails;
				}

				return $this->rows;
			}

			public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): array {
				if ($this->patchFails !== null) {
					throw $this->patchFails;
				}

				$this->patches[] = ['id' => $objectId, 'data' => $data, 'register' => $register, 'schema' => $schema];
				return ['id' => $objectId] + $data;
			}
		};

		$this->settings->method('getObjectService')->willReturnCallback(fn (): object => $this->objects);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => [
				'register' => 'dossiq',
				'case_schema' => 'case',
				'termijn_instance_schema' => 'termijn-instance',
			][$key] ?? ''
		);

		$this->termService->method('updateTermijnInstance')->willReturnCallback(
			static fn (string $id, array $patch): array => ['id' => $id] + $patch
		);
		$this->termService->method('recordEvent')->willReturnCallback(
			function (string $termInstanceId, string $type, string $basis, string $rationale): array {
				$this->events[] = [$termInstanceId, $type, $basis, $rationale];
				return ['type' => $type];
			}
		);
		$this->timeline->method('record')->willReturn('entry');
		$this->aanvullingen->method('openFor')->willReturn(['recipient' => ' aanvrager@example.org ']);
		$this->reasons->method('caseTypeForCase')->willReturn(
			['handling' => ['automaticMessages' => [PauseChaseService::TEMPLATE]]]
		);
		$this->reasons->method('reasonsIn')->willReturn([$this->reason()]);
	}//end setUp()

	/**
	 * The service, wired.
	 *
	 * @param bool $withRequests Whether the open-request service is available.
	 *
	 * @return PauseChaseService The service under test.
	 */
	private function service(bool $withRequests = true): PauseChaseService {
		$requests = null;
		if ($withRequests === true) {
			$requests = $this->aanvullingen;
		}

		return new PauseChaseService(
			termService: $this->termService,
			chase: new PauseChaseSources(
				reasons: $this->reasons,
				schedule: new ChaseSchedule(
					calendar: new WorkingDayCalculator(),
					dates: $this->caseDates(),
				),
				notifications: $this->notifications,
			),
			timeline: $this->timeline,
			settings: $this->settings,
			logger: new NullLogger(),
			aanvullingen: $requests,
		);
	}//end service()

	/**
	 * The declared reason, with whatever this test needs different.
	 *
	 * @param array<string, mixed> $overrides Raw reason fields to change.
	 *
	 * @return array<string, mixed> The normalised reason.
	 */
	private function reason(array $overrides = []): array {
		return PauseReason::normalise(
			row: array_merge(
				[
					'key' => 'aanvulling-aanvrager',
					'name' => 'Aanvulling gevraagd',
					'category' => 'applicant',
					'legalBasis' => 'Awb 4:5',
					'chaseIntervalDays' => 5,
					'chaseBudget' => 2,
					'countsWorkingDays' => false,
					'chaseText' => 'Wij hebben uw aanvulling nog niet ontvangen.',
					'escalateTo' => 'handler',
				],
				$overrides
			)
		);
	}//end reason()

	/**
	 * Swap the declared reason for one with different fields.
	 *
	 * @param array<string, mixed> $overrides Raw reason fields to change.
	 *
	 * @return void
	 */
	private function declare(array $overrides): void {
		$this->reasons = $this->createMock(PauseReasonReader::class);
		$this->reasons->method('caseTypeForCase')->willReturn(
			['handling' => ['automaticMessages' => [PauseChaseService::TEMPLATE]]]
		);
		$this->reasons->method('reasonsIn')->willReturn([$this->reason($overrides)]);
	}//end declare()

	/**
	 * One suspended instance.
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
	 * The sweep asks for suspended terms only, chases the one that is due,
	 * escalates the one whose budget is spent, and leaves the rest alone.
	 *
	 * @return void
	 */
	public function testTheSweepChasesAndEscalatesWhatIsDueAndCountsIt(): void {
		$rows = [
			't1' => $this->instance(),
			't2' => $this->instance(['id' => 't2', 'case' => 'c2', 'chasesSent' => 2, 'lastChasedAt' => '2026-09-01T09:00:00+02:00']),
			't3' => $this->instance(['id' => 't3', 'case' => 'c3', 'chasesSent' => 1, 'lastChasedAt' => '2026-09-05T09:00:00+02:00']),
		];
		$this->objects->rows = array_values($rows);
		$this->termService->method('getTermijnInstance')->willReturnCallback(
			static fn (string $id): ?array => $rows[$id] ?? null
		);
		$this->notifications->expects($this->once())
			->method('sendTermijnNotification')
			->with(PauseChaseService::TEMPLATE, 't1', 'aanvrager@example.org', $this->anything())
			->willReturn([]);

		$done = $this->service()->sweep(now: new DateTimeImmutable('2026-09-07T10:00:00+02:00'));

		$this->assertSame(['chased' => 1, 'escalated' => 1], $done);
		$this->assertSame(
			[['dossiq', 'termijn-instance', ['status' => 'paused', '_limit' => 500]]],
			$this->objects->searches
		);
		$this->assertSame(
			[
				['t1', PauseChaseService::EVENT_CHASED, 'Awb 4:5', 'Reminder 1 of 2 sent: Aanvulling gevraagd'],
				['t2', PauseChaseService::EVENT_ESCALATED, 'Awb 4:5', 'No reply after 2 reminders. Escalated to handler.'],
			],
			$this->events
		);
	}//end testTheSweepChasesAndEscalatesWhatIsDueAndCountsIt()

	/**
	 * Without OpenRegister there is nothing to sweep, and nothing is asked.
	 *
	 * @return void
	 */
	public function testASweepWithoutAnObjectServiceDoesNothing(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getObjectService')->willReturn(null);
		$this->settings->method('getConfigValue')->willReturn('dossiq');
		$this->termService->expects($this->never())->method('getTermijnInstance');

		$this->assertSame(['chased' => 0, 'escalated' => 0], $this->service()->sweep());
	}//end testASweepWithoutAnObjectServiceDoesNothing()

	/**
	 * A sweep with no term schema configured asks nothing.
	 *
	 * @return void
	 */
	public function testASweepWithoutATermSchemaAsksNothing(): void {
		$this->settings = $this->createMock(SettingsService::class);
		$this->settings->method('getObjectService')->willReturn($this->objects);
		$this->settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register') ? 'dossiq' : ''
		);

		$this->assertSame(['chased' => 0, 'escalated' => 0], $this->service()->sweep());
		$this->assertSame([], $this->objects->searches);
	}//end testASweepWithoutATermSchemaAsksNothing()

	/**
	 * A search that fails reaches the caller: "we could not ask" must not read
	 * as "nothing is suspended".
	 *
	 * @return void
	 */
	public function testASweepThatCannotAskThrowsInsteadOfReportingAQuietNight(): void {
		$this->objects->searchFails = new RuntimeException('register unavailable');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('register unavailable');

		$this->service()->sweep();
	}//end testASweepThatCannotAskThrowsInsteadOfReportingAQuietNight()

	/**
	 * After a reminder the case carries who it waits on, how often it was
	 * chased and since when, patched onto the case and nothing else.
	 *
	 * @return void
	 */
	public function testAReminderWritesTheWaitingFactsOntoTheCase(): void {
		$this->termService->method('getTermijnInstance')->willReturn($this->instance());
		$this->notifications->method('sendTermijnNotification')->willReturn([]);

		$this->assertTrue(
			$this->service()->chaseIfDue(instance: $this->instance(), now: new DateTimeImmutable('2026-09-06T10:00:00+02:00'))
		);

		$this->assertSame(
			[
				[
					'id' => 'c1',
					'data' => [
						'pauseWaitingOn' => 'applicant',
						'chasesSent' => 1,
						'waitingSince' => '2026-09-01T00:00:00+02:00',
					],
					'register' => 'dossiq',
					'schema' => 'case',
				],
			],
			$this->objects->patches
		);
	}//end testAReminderWritesTheWaitingFactsOntoTheCase()

	/**
	 * A second reminder on a pause with no readable start date still writes
	 * the waiting facts, just without a date it would have to invent.
	 *
	 * @return void
	 */
	public function testAnUnreadablePauseStartLeavesWaitingSinceOut(): void {
		$instance = $this->instance(['pauzeStartDatum' => '', 'chasesSent' => 1, 'lastChasedAt' => '2026-09-01T09:00:00+02:00']);
		$this->termService->method('getTermijnInstance')->willReturn($instance);
		$this->notifications->method('sendTermijnNotification')->willReturn([]);

		$this->service()->chaseIfDue(instance: $instance, now: new DateTimeImmutable('2026-09-06T10:00:00+02:00'));

		$this->assertCount(1, $this->objects->patches);
		$this->assertSame(['pauseWaitingOn' => 'applicant', 'chasesSent' => 2], $this->objects->patches[0]['data']);
	}//end testAnUnreadablePauseStartLeavesWaitingSinceOut()

	/**
	 * The waiting sentence is a read convenience: when writing it fails, the
	 * reminder still went out and is still counted.
	 *
	 * @return void
	 */
	public function testAFailedCaseProjectionDoesNotUndoTheReminder(): void {
		$this->objects->patchFails = new RuntimeException('case locked');
		$this->termService->method('getTermijnInstance')->willReturn($this->instance());
		$this->notifications->expects($this->once())->method('sendTermijnNotification')->willReturn([]);

		$this->assertTrue(
			$this->service()->chaseIfDue(instance: $this->instance(), now: new DateTimeImmutable('2026-09-06T10:00:00+02:00'))
		);
		$this->assertSame(PauseChaseService::EVENT_CHASED, $this->events[0][1]);
	}//end testAFailedCaseProjectionDoesNotUndoTheReminder()

	/**
	 * An open request that cannot be read gives no address, so nothing is sent
	 * and nothing is counted.
	 *
	 * @return void
	 */
	public function testAnUnreadableOpenRequestSendsNothing(): void {
		$this->aanvullingen = $this->createMock(AanvullingsverzoekService::class);
		$this->aanvullingen->method('openFor')->willThrowException(new RuntimeException('request store down'));
		$this->termService->method('getTermijnInstance')->willReturn($this->instance());
		$this->termService->expects($this->never())->method('updateTermijnInstance');
		$this->notifications->expects($this->never())->method('sendTermijnNotification');

		$this->assertFalse(
			$this->service()->chaseIfDue(instance: $this->instance(), now: new DateTimeImmutable('2026-09-06T10:00:00+02:00'))
		);
		$this->assertSame([], $this->events);
	}//end testAnUnreadableOpenRequestSendsNothing()

	/**
	 * An installation without the request service has no address to follow,
	 * so the case is not chased.
	 *
	 * @return void
	 */
	public function testWithoutTheRequestServiceNothingIsSent(): void {
		$this->termService->method('getTermijnInstance')->willReturn($this->instance());
		$this->notifications->expects($this->never())->method('sendTermijnNotification');

		$this->assertFalse(
			$this->service(withRequests: false)->chaseIfDue(
				instance: $this->instance(),
				now: new DateTimeImmutable('2026-09-06T10:00:00+02:00')
			)
		);
	}//end testWithoutTheRequestServiceNothingIsSent()

	/**
	 * A trigger row with neither id nor uuid is not looked up at all.
	 *
	 * @return void
	 */
	public function testARowWithoutAnIdIsNotLookedUp(): void {
		$this->termService->expects($this->never())->method('getTermijnInstance');

		$this->assertFalse($this->service()->chaseIfDue(instance: ['id' => '  ']));
		$this->assertFalse($this->service()->escalateIfDue(instance: []));
	}//end testARowWithoutAnIdIsNotLookedUp()

	/**
	 * The uuid is used when the row carries no id, and a stored row with no
	 * case is not chased.
	 *
	 * @return void
	 */
	public function testARowFoundByUuidButWithoutACaseIsNotChased(): void {
		$this->termService->expects($this->once())
			->method('getTermijnInstance')
			->with('u-1')
			->willReturn($this->instance(['id' => 'u-1', 'case' => '']));
		$this->reasons->expects($this->never())->method('caseTypeForCase');
		$this->notifications->expects($this->never())->method('sendTermijnNotification');

		$this->assertFalse(
			$this->service()->chaseIfDue(instance: ['uuid' => 'u-1'], now: new DateTimeImmutable('2026-09-06T10:00:00+02:00'))
		);
	}//end testARowFoundByUuidButWithoutACaseIsNotChased()

	/**
	 * A reason with no name is written under its key, and one with no legal
	 * basis is recorded under Awb 4:5.
	 *
	 * @return void
	 */
	public function testANamelessReasonWithoutABasisFallsBackToItsKeyAndAwb45(): void {
		$this->declare(['name' => '', 'legalBasis' => '']);
		$this->termService->method('getTermijnInstance')->willReturn($this->instance());
		$this->notifications->method('sendTermijnNotification')->willReturn([]);

		$this->assertTrue(
			$this->service()->chaseIfDue(instance: $this->instance(), now: new DateTimeImmutable('2026-09-06T10:00:00+02:00'))
		);
		$this->assertSame(
			[['t1', PauseChaseService::EVENT_CHASED, 'Awb 4:5', 'Reminder 1 of 2 sent: aanvulling-aanvrager']],
			$this->events
		);
	}//end testANamelessReasonWithoutABasisFallsBackToItsKeyAndAwb45()

	/**
	 * A declared legal basis is the one the event is recorded under.
	 *
	 * @return void
	 */
	public function testADeclaredBasisIsTheOneRecorded(): void {
		$this->declare(['legalBasis' => 'Wet open overheid art. 4.2']);
		$this->termService->method('getTermijnInstance')->willReturn($this->instance());
		$this->notifications->method('sendTermijnNotification')->willReturn([]);

		$this->service()->chaseIfDue(instance: $this->instance(), now: new DateTimeImmutable('2026-09-06T10:00:00+02:00'));

		$this->assertSame('Wet open overheid art. 4.2', $this->events[0][2]);
	}//end testADeclaredBasisIsTheOneRecorded()

	/**
	 * An escalation with nobody named to tell says only that nobody replied.
	 *
	 * @return void
	 */
	public function testAnEscalationWithNobodyToTellNamesNobody(): void {
		$this->declare(['escalateTo' => '']);
		$instance = $this->instance(['chasesSent' => 2, 'lastChasedAt' => '2026-09-11T09:00:00+02:00']);
		$this->termService->method('getTermijnInstance')->willReturn($instance);

		$this->assertTrue(
			$this->service()->escalateIfDue(instance: $instance, now: new DateTimeImmutable('2026-09-16T10:00:00+02:00'))
		);
		$this->assertSame(
			[['t1', PauseChaseService::EVENT_ESCALATED, 'Awb 4:5', 'No reply after 2 reminders.']],
			$this->events
		);
	}//end testAnEscalationWithNobodyToTellNamesNobody()
}//end class
