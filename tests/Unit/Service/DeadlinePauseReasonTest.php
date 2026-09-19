<?php

/**
 * A pause is registered under a declared reason, and refuses one that is not.
 *
 * The reason is what carries the chasing schedule, so what is pinned here is
 * that the key and the party reach the instance, that the reminder rungs reach
 * the engine, that a stale key is refused rather than stored, and that resuming
 * clears the lot.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\Pause\ChaseSchedule;
use OCA\Dossiq\Service\Pause\PauseReason;
use OCA\Dossiq\Service\Pause\PauseReasonReader;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * REQ-TERM-011: a pause names a reason that chases.
 *
 * @covers \OCA\Dossiq\Service\DeadlinePauseService
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\Pause\ChaseSchedule
 * @uses \OCA\Dossiq\Service\Pause\PauseReason
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 */
class DeadlinePauseReasonTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The store.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService $termService;

	/**
	 * What the case type declared.
	 *
	 * @var PauseReasonReader&MockObject
	 */
	private PauseReasonReader $reasons;

	/**
	 * The engine bridge.
	 *
	 * @var TermijnTimerService&MockObject
	 */
	private TermijnTimerService $timerService;

	/**
	 * The patches written onto the instance.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $patches = [];

	/**
	 * Wire the service against a store holding one running term.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->termService = $this->createMock(TermijnService::class);
		$this->reasons = $this->createMock(PauseReasonReader::class);
		$this->timerService = $this->createMock(TermijnTimerService::class);
		$this->patches = [];

		$this->termService->method('getTermijnInstance')->willReturn(
			[
				'id' => 't1',
				'case' => 'c1',
				'endDateCurrent' => '2026-11-01',
				'status' => 'lopend',
			]
		);
		$this->termService->method('updateTermijnInstance')->willReturnCallback(
			function (string $id, array $patch): array {
				$this->patches[] = $patch;
				return array_merge(['id' => $id], $patch);
			}
		);
		$this->timerService->method('rollTermEndFor')->willReturnArgument(0);
		$this->reasons->method('forCase')->willReturn([$this->reason()]);
	}//end setUp()

	/**
	 * The service, wired.
	 *
	 * @return DeadlinePauseService The service under test.
	 */
	private function service(): DeadlinePauseService {
		return new DeadlinePauseService(
			termService: $this->termService,
			timerService: $this->timerService,
			declarations: null,
			reasons: $this->reasons,
			schedule: new ChaseSchedule(
				calendar: new WorkingDayCalculator(),
				dates: $this->caseDates(),
			),
		);
	}//end service()

	/**
	 * The one reason the case type declares.
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
			]
		);
	}//end reason()

	/**
	 * The reason and the party it waits on are stored on the instance, and the
	 * reminder rungs reach the engine.
	 *
	 * @return void
	 */
	public function testThePauseCarriesItsReasonAndArmsTheReminders(): void {
		$this->timerService->expects($this->once())
			->method('armHersteltermijn')
			->with($this->anything(), 14, [9, 4])
			->willReturn('timer-1');

		$this->service()->registerPauze(
			termInstanceId: 't1',
			durationDays: 14,
			rationale: 'De aanvraag is onvolledig',
			pauseReason: 'aanvulling-aanvrager',
		);

		$this->assertSame('aanvulling-aanvrager', $this->patches[0]['pauseReason']);
		$this->assertSame('applicant', $this->patches[0]['pauseWaitingOn']);
		$this->assertSame(0, $this->patches[0]['chasesSent']);
	}//end testThePauseCarriesItsReasonAndArmsTheReminders()

	/**
	 * A key the case type does not declare is refused, naming what to do,
	 * rather than stored as a reason nothing resolves.
	 *
	 * @return void
	 */
	public function testAReasonTheCaseTypeDoesNotDeclareIsRefused(): void {
		$this->expectException(RefusedException::class);

		$this->service()->registerPauze(
			termInstanceId: 't1',
			durationDays: 14,
			rationale: 'De aanvraag is onvolledig',
			pauseReason: 'iets-anders',
		);
	}//end testAReasonTheCaseTypeDoesNotDeclareIsRefused()

	/**
	 * A caller that names no reason keeps the behaviour that was here before:
	 * a rationale, a suspended clock and no chasing.
	 *
	 * @return void
	 */
	public function testAPauseWithoutAReasonStillSuspends(): void {
		$this->timerService->expects($this->once())
			->method('armHersteltermijn')
			->with($this->anything(), 14, [])
			->willReturn('timer-1');

		$this->service()->registerPauze(
			termInstanceId: 't1',
			durationDays: 14,
			rationale: 'De aanvraag is onvolledig',
		);

		$this->assertSame('', $this->patches[0]['pauseReason']);
		$this->assertSame('paused', $this->patches[0]['status']);
	}//end testAPauseWithoutAReasonStillSuspends()

	/**
	 * Resuming clears the reason and its counters, so a term nobody waits on
	 * no longer reads as chased.
	 *
	 * @return void
	 */
	public function testResumingClearsTheReasonAndTheCounters(): void {
		$termService = $this->createMock(TermijnService::class);
		$patches = [];
		$termService->method('getTermijnInstance')->willReturn(
			[
				'id' => 't1',
				'case' => 'c1',
				'endDateCurrent' => '2026-11-15',
				'status' => 'paused',
				'pauzeStartDatum' => '2026-09-01',
				'pauzeDuurDagen' => 14,
				'pauseReason' => 'aanvulling-aanvrager',
				'chasesSent' => 2,
			]
		);
		$termService->method('updateTermijnInstance')->willReturnCallback(
			static function (string $id, array $patch) use (&$patches): array {
				$patches[] = $patch;
				return array_merge(['id' => $id], $patch);
			}
		);

		$service = new DeadlinePauseService(
			termService: $termService,
			timerService: $this->timerService,
			declarations: null,
			reasons: $this->reasons,
			schedule: new ChaseSchedule(
				calendar: new WorkingDayCalculator(),
				dates: $this->caseDates(),
			),
		);

		$service->resumeAfterPauze(termInstanceId: 't1');

		$this->assertSame('', $patches[0]['pauseReason']);
		$this->assertSame('', $patches[0]['pauseWaitingOn']);
		$this->assertSame(0, $patches[0]['chasesSent']);
		$this->assertNull($patches[0]['lastChasedAt']);
	}//end testResumingClearsTheReasonAndTheCounters()
}//end class
