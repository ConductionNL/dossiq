<?php

/**
 * The four lifecycle gestures, on a case whose store remembers what was saved.
 *
 * The store mock writes back into the case it hands out, because these
 * gestures are only meaningful in sequence: suspend then resume, extend twice.
 * A store that always answers the same case would let a broken `isSuspended`
 * pass every test in the file.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CaseLifecycleService;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Suspend, resume, extend and reopen.
 *
 * @covers \OCA\Dossiq\Service\CaseLifecycleService
 */
class CaseLifecycleServiceTest extends TestCase {

	/**
	 * The case as the store currently holds it.
	 *
	 * @var array<string, mixed>
	 */
	private array $case;

	/**
	 * The store.
	 *
	 * @var CaseStatusStore&MockObject
	 */
	private CaseStatusStore $store;

	/**
	 * The case type's rules.
	 *
	 * @var CaseTypeReader&MockObject
	 */
	private CaseTypeReader $caseTypes;

	/**
	 * The statutory-term instances.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService $termService;

	/**
	 * Opschorten / hervatten.
	 *
	 * @var DeadlinePauseService&MockObject
	 */
	private DeadlinePauseService $pauseService;

	/**
	 * Verlengen.
	 *
	 * @var DeadlineExtensionService&MockObject
	 */
	private DeadlineExtensionService $extensionService;

	/**
	 * The service under test.
	 *
	 * @var CaseLifecycleService
	 */
	private CaseLifecycleService $service;

	/**
	 * An open case of a type that allows both suspension and extension.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->case = [
			'id' => 'case-1',
			'caseType' => 'ct-1',
			'status' => 'st-progress',
			'plannedEndDate' => '2026-09-01',
			'extensionCount' => 0,
		];

		$this->store = $this->createMock(CaseStatusStore::class);
		$this->store->method('loadCase')->willReturnCallback(fn (): array => $this->case);
		$this->store->method('saveCase')->willReturnCallback(
			function (array $case): array {
				$this->case = $case;
				return $case;
			}
		);
		$this->store->method('lookupStatusName')->willReturn('In behandeling');

		$this->caseTypes = $this->createMock(CaseTypeReader::class);
		$this->caseTypes->method('read')->willReturn(
			[
				'suspensionAllowed' => true,
				'extensionAllowed' => true,
				'extensionPeriod' => 'P14D',
				'initialStatus' => 'st-received',
				'title' => 'Vergunning',
			]
		);
		$this->caseTypes->method('isFinalStatus')->willReturnCallback(
			static fn (string $statusTypeId): bool => $statusTypeId === 'st-done'
		);
		$this->caseTypes->method('statusBelongsTo')->willReturn(true);

		$this->termService = $this->createMock(TermijnService::class);
		$this->termService->method('getTermijnInstanceForZaak')->willReturn(null);
		$this->pauseService = $this->createMock(DeadlinePauseService::class);
		$this->extensionService = $this->createMock(DeadlineExtensionService::class);

		$this->service = new CaseLifecycleService(
			store: $this->store,
			caseTypes: $this->caseTypes,
			termService: $this->termService,
			pauseService: $this->pauseService,
			extensionService: $this->extensionService,
			logger: $this->createMock(LoggerInterface::class),
		);
	}//end setUp()

	/**
	 * Every gesture asks for a reason, and refuses an empty one before
	 * touching anything.
	 *
	 * @return void
	 */
	public function testAGestureWithoutAReasonIsRefused(): void {
		$this->store->expects($this->never())->method('saveCase');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('reason_required');

		$this->service->suspend(caseId: 'case-1', reason: '   ', days: 14);
	}//end testAGestureWithoutAReasonIsRefused()

	/**
	 * A case type that does not allow suspension refuses it.
	 *
	 * @return void
	 */
	public function testSuspendIsRefusedWhenTheCaseTypeForbidsIt(): void {
		$caseTypes = $this->createMock(CaseTypeReader::class);
		$caseTypes->method('read')->willReturn(
			[
				'suspensionAllowed' => false,
				'extensionAllowed' => false,
				'extensionPeriod' => '',
				'initialStatus' => 'st-received',
				'title' => 'Melding',
			]
		);
		$caseTypes->method('isFinalStatus')->willReturn(false);

		$service = new CaseLifecycleService(
			store: $this->store,
			caseTypes: $caseTypes,
			termService: $this->termService,
			pauseService: $this->pauseService,
			extensionService: $this->extensionService,
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('suspension_not_allowed');

		$service->suspend(caseId: 'case-1', reason: 'Aanvulling', days: 14);
	}//end testSuspendIsRefusedWhenTheCaseTypeForbidsIt()

	/**
	 * Suspending marks the case, and resuming clears the mark.
	 *
	 * @return void
	 */
	public function testSuspendThenResume(): void {
		$afterSuspend = $this->service->suspend(caseId: 'case-1', reason: 'Aanvulling gevraagd', days: 14);

		$this->assertTrue($afterSuspend['suspended']);
		$this->assertTrue($afterSuspend['canResume']);
		$this->assertFalse($afterSuspend['canSuspend'], 'a suspended case is not offered Suspend again');

		$afterResume = $this->service->resume(caseId: 'case-1', reason: 'Aanvulling ontvangen');

		$this->assertFalse($afterResume['suspended']);
		$this->assertTrue($afterResume['canSuspend']);
	}//end testSuspendThenResume()

	/**
	 * Suspending twice is refused: the second one would journal a state the
	 * case is already in and pause a clock that is already paused.
	 *
	 * @return void
	 */
	public function testSuspendingASuspendedCaseIsRefused(): void {
		$this->service->suspend(caseId: 'case-1', reason: 'Aanvulling gevraagd', days: 14);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('already_suspended');

		$this->service->suspend(caseId: 'case-1', reason: 'Nog eens', days: 7);
	}//end testSuspendingASuspendedCaseIsRefused()

	/**
	 * A case with a TermijnInstance moves its statutory clock too.
	 *
	 * @return void
	 */
	public function testSuspendPausesTheStatutoryClockWhenThereIsOne(): void {
		$termService = $this->createMock(TermijnService::class);
		$termService->method('getTermijnInstanceForZaak')->willReturn(['id' => 'ti-1']);

		$pauseService = $this->createMock(DeadlinePauseService::class);
		$pauseService->expects($this->once())
			->method('registerPauze')
			->with(termInstanceId: 'ti-1', durationDays: 14, rationale: 'Aanvulling gevraagd')
			->willReturn([]);

		$service = new CaseLifecycleService(
			store: $this->store,
			caseTypes: $this->caseTypes,
			termService: $termService,
			pauseService: $pauseService,
			extensionService: $this->extensionService,
			logger: $this->createMock(LoggerInterface::class),
		);

		$service->suspend(caseId: 'case-1', reason: 'Aanvulling gevraagd', days: 14);
	}//end testSuspendPausesTheStatutoryClockWhenThereIsOne()

	/**
	 * Extending adds the case type's period to the end date and counts the
	 * extension.
	 *
	 * @return void
	 */
	public function testExtendAddsThePeriodAndCountsIt(): void {
		$state = $this->service->extend(caseId: 'case-1', reason: 'Meer onderzoek nodig');

		$this->assertSame('2026-09-15', $state['deadline'], 'P14D past 2026-09-01');
		$this->assertSame(1, $state['extensionCount']);

		$second = $this->service->extend(caseId: 'case-1', reason: 'Nog meer onderzoek');

		$this->assertSame('2026-09-29', $second['deadline']);
		$this->assertSame(2, $second['extensionCount']);
	}//end testExtendAddsThePeriodAndCountsIt()

	/**
	 * An explicit new end date wins over the case type's period. This is what
	 * the bulk Extend term action passes when a reader picks a date.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	 */
	public function testExtendHonoursAnExplicitNewEndDate(): void {
		$state = $this->service->extend(
			caseId: 'case-1',
			reason: 'Complexe zaak',
			newEndDate: '2026-12-01',
		);

		$this->assertSame('2026-12-01', $state['deadline']);
		$this->assertSame(1, $state['extensionCount']);
	}//end testExtendHonoursAnExplicitNewEndDate()

	/**
	 * A named date that is not LATER than the current end is refused: an
	 * extension that shortens the term is a mistake, and refusing it is
	 * cheaper than explaining it afterwards.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-case-list/specs/case-bulk-status-transition/spec.md
	 */
	public function testExtendRefusesADateThatIsNotLater(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('new_end_date_not_later');

		$this->service->extend(caseId: 'case-1', reason: 'Terug in de tijd', newEndDate: '2026-08-01');
	}//end testExtendRefusesADateThatIsNotLater()

	/**
	 * A named date nothing can read is refused rather than silently falling
	 * back to the case type's period, which would extend the term by an
	 * amount nobody asked for.
	 *
	 * @return void
	 */
	public function testExtendRefusesAnUnreadableDate(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('new_end_date_unreadable');

		$this->service->extend(caseId: 'case-1', reason: 'Onleesbaar', newEndDate: 'volgende maand');
	}//end testExtendRefusesAnUnreadableDate()

	/**
	 * The case type's rule still governs a named date: `extensionAllowed`
	 * false refuses whatever date the caller picked.
	 *
	 * @return void
	 */
	public function testAnExplicitDateDoesNotBypassTheCaseTypeRule(): void {
		$caseTypes = $this->createMock(CaseTypeReader::class);
		$caseTypes->method('read')->willReturn(
			[
				'suspensionAllowed' => true,
				'extensionAllowed' => false,
				'extensionPeriod' => 'P14D',
				'initialStatus' => 'st-received',
				'title' => 'Melding',
			]
		);
		$caseTypes->method('isFinalStatus')->willReturn(false);

		$service = new CaseLifecycleService(
			store: $this->store,
			caseTypes: $caseTypes,
			termService: $this->termService,
			pauseService: $this->pauseService,
			extensionService: $this->extensionService,
			logger: $this->createMock(LoggerInterface::class),
		);

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('extension_not_allowed');

		$service->extend(caseId: 'case-1', reason: 'Toch maar', newEndDate: '2026-12-01');
	}//end testAnExplicitDateDoesNotBypassTheCaseTypeRule()

	/**
	 * An open case cannot be reopened.
	 *
	 * @return void
	 */
	public function testReopenIsRefusedOnAnOpenCase(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('case_not_closed');

		$this->service->reopen(caseId: 'case-1', reason: 'Nieuw feit');
	}//end testReopenIsRefusedOnAnOpenCase()

	/**
	 * A closed case goes back to its type's initial status, and the move is
	 * written into the history like any other.
	 *
	 * @return void
	 */
	public function testReopenReturnsTheCaseToItsInitialStatus(): void {
		$this->case['status'] = 'st-done';
		$this->store->expects($this->once())
			->method('writeStatusRecord')
			->with(
				caseId: 'case-1',
				toStatus: 'st-received',
				fromStatus: 'st-done',
				label: 'Reopened',
				comment: 'Nieuw feit',
				evaluatedGuards: [],
				noWorkflowTemplate: true,
			)
			->willReturn(['id' => 'rec-1']);

		$state = $this->service->reopen(caseId: 'case-1', reason: 'Nieuw feit');

		$this->assertSame('st-received', $this->case['status']);
		$this->assertFalse($state['isFinalStatus']);
	}//end testReopenReturnsTheCaseToItsInitialStatus()

	/**
	 * A closed case offers Reopen and nothing else.
	 *
	 * @return void
	 */
	public function testAClosedCaseOffersOnlyReopen(): void {
		$this->case['status'] = 'st-done';

		$state = $this->service->state(caseId: 'case-1');

		$this->assertTrue($state['canReopen']);
		$this->assertFalse($state['canSuspend']);
		$this->assertFalse($state['canExtend']);
	}//end testAClosedCaseOffersOnlyReopen()
}//end class
