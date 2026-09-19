<?php

/**
 * Four clocks on one case, each naming its kind.
 *
 * The vocabulary and the read. A term instance written before the four kinds
 * existed carries no `kind` at all, so the first thing these cases pin is that
 * such a row reads as the citizen's term rather than as nothing.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use DateTimeImmutable;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermCalendarGuard;
use OCA\Dossiq\Service\TermDeclarationReader;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\TermKind;
use OCA\Dossiq\Service\WorkingDayCalculator;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-TERM-060: every clock on a case is a term instance with a kind.
 *
 * @covers \OCA\Dossiq\Service\TermKind
 * @covers \OCA\Dossiq\Service\CaseTermsService
 * @uses \OCA\Dossiq\Exception\RefusedException
 * @uses \OCA\Dossiq\Service\CaseDateNormaliser
 * @uses \OCA\Dossiq\Service\TermCalendarGuard
 * @uses \OCA\Dossiq\Service\TermijnTimerService
 * @uses \OCA\Dossiq\Service\WorkingDayCalculator
 * @uses \OCA\Dossiq\Service\Termijn\TermEndRoll
 */
class TermijnKindTest extends TestCase {
	use MakesCaseDateNormaliser;

	/**
	 * The term instances the store answers with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $instances = [];

	/**
	 * The store.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService $termService;

	/**
	 * The declarations.
	 *
	 * @var TermDeclarationReader&MockObject
	 */
	private TermDeclarationReader $declarations;

	/**
	 * The calendar bridge.
	 *
	 * @var TermijnTimerService&MockObject
	 */
	private TermijnTimerService $timers;

	/**
	 * The service under test.
	 *
	 * @var CaseTermsService
	 */
	private CaseTermsService $service;

	/**
	 * Wire the service against a store that answers with $this->instances.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->termService = $this->createMock(TermijnService::class);
		$this->declarations = $this->createMock(TermDeclarationReader::class);
		$this->timers = $this->createMock(TermijnTimerService::class);

		$this->termService->method('instancesForCase')->willReturnCallback(
			fn (string $caseId): array => $this->instances
		);

		// The calendar answers the date it was handed, so these cases pin the
		// kind bookkeeping and not the roll. The roll has its own fixtures.
		$this->timers->method('rollTermEndFor')->willReturnArgument(0);

		$this->service = new CaseTermsService(
			termService: $this->termService,
			declarations: $this->declarations,
			timers: $this->timers,
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * An instance written before the kinds existed reads as statutory.
	 *
	 * @return void
	 */
	public function testAnInstanceWithNoKindReadsAsStatutory(): void {
		self::assertSame(TermKind::STATUTORY, TermKind::ofInstance(['case' => 'c1']));
	}//end testAnInstanceWithNoKindReadsAsStatutory()

	/**
	 * A kind nobody recognises reads as statutory too, so the clock is shown
	 * rather than hidden.
	 *
	 * @return void
	 */
	public function testAnUnknownKindReadsAsStatutory(): void {
		self::assertSame(TermKind::STATUTORY, TermKind::ofInstance(['kind' => 'something-else']));
		self::assertFalse(TermKind::isKnown('something-else'));
	}//end testAnUnknownKindReadsAsStatutory()

	/**
	 * Four clocks on one case come back as four instances, each naming its kind.
	 *
	 * @return void
	 */
	public function testFourClocksOnOneCaseAreFourInstances(): void {
		$this->instances = [
			$this->instance(kind: TermKind::STATUTORY, end: '2026-11-01'),
			$this->instance(kind: TermKind::PLANNED, end: '2026-10-01'),
			$this->instance(kind: TermKind::INTERNAL, end: '2026-09-25'),
			$this->instance(kind: TermKind::PHASE, end: '2026-09-20'),
		];

		$terms = $this->service->termsForCase(caseId: 'c1', now: new DateTimeImmutable('2026-09-15'));

		self::assertCount(4, $terms);
		self::assertSame(
			[TermKind::STATUTORY, TermKind::PLANNED, TermKind::INTERNAL, TermKind::PHASE],
			array_column($terms, 'kind')
		);
	}//end testFourClocksOnOneCaseAreFourInstances()

	/**
	 * Days left counts forward, and negative once the end has passed.
	 *
	 * @return void
	 */
	public function testDaysLeftGoesNegativeOnceTheEndHasPassed(): void {
		$this->instances = [
			$this->instance(kind: TermKind::STATUTORY, end: '2026-09-25'),
			$this->instance(kind: TermKind::PHASE, end: '2026-09-10'),
		];

		$terms = $this->service->termsForCase(caseId: 'c1', now: new DateTimeImmutable('2026-09-15'));

		self::assertSame(10, $terms[0]['daysLeft']);
		self::assertFalse($terms[0]['overdue']);
		self::assertSame(-5, $terms[1]['daysLeft']);
		self::assertTrue($terms[1]['overdue']);
	}//end testDaysLeftGoesNegativeOnceTheEndHasPassed()

	/**
	 * A completed clock is not overdue, however far past its end the day is.
	 *
	 * @return void
	 */
	public function testACompletedClockIsNotOverdue(): void {
		$this->instances = [
			$this->instance(kind: TermKind::STATUTORY, end: '2026-01-01', status: 'completed'),
		];

		$terms = $this->service->termsForCase(caseId: 'c1', now: new DateTimeImmutable('2026-09-15'));

		self::assertFalse($terms[0]['overdue'], 'A term that was answered is late for nobody.');
	}//end testACompletedClockIsNotOverdue()

	/**
	 * Only the statutory term may reach a citizen surface.
	 *
	 * @return void
	 */
	public function testOnlyTheStatutoryTermIsCitizenVisible(): void {
		self::assertTrue(TermKind::isCitizenVisible(TermKind::STATUTORY));
		self::assertFalse(TermKind::isCitizenVisible(TermKind::PLANNED));
		self::assertFalse(TermKind::isCitizenVisible(TermKind::INTERNAL));
		self::assertFalse(TermKind::isCitizenVisible(TermKind::PHASE));
	}//end testOnlyTheStatutoryTermIsCitizenVisible()

	/**
	 * The citizen read drops the other three kinds rather than labelling them.
	 *
	 * @return void
	 */
	public function testTheCitizenReadCarriesTheStatutoryTermAlone(): void {
		$this->instances = [
			$this->instance(kind: TermKind::STATUTORY, end: '2026-11-01'),
			$this->instance(kind: TermKind::PLANNED, end: '2026-10-01'),
			$this->instance(kind: TermKind::INTERNAL, end: '2026-09-25'),
		];

		$visible = $this->service->citizenTermsFor(caseId: 'c1', now: new DateTimeImmutable('2026-09-15'));

		self::assertCount(1, $visible);
		self::assertSame(TermKind::STATUTORY, $visible[0]['kind']);
	}//end testTheCitizenReadCarriesTheStatutoryTermAlone()

	/**
	 * Every bound clock goes through the calendar bridge.
	 *
	 * The mutation that matters: a bind that computed its own end date would
	 * never call this, and the assertion below is the only thing that notices.
	 *
	 * @return void
	 */
	public function testEveryBoundClockReachesTheCalendar(): void {
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->expects(self::once())
			->method('rollTermEndFor')
			->willReturnArgument(0);

		$service = new CaseTermsService(
			termService: $this->termService,
			declarations: $this->declarations,
			timers: $timers,
			logger: new NullLogger(),
		);

		$service->bindLeadTime(
			caseId: 'c1',
			kind: TermKind::PLANNED,
			days: 30,
			start: new DateTimeImmutable('2026-09-15'),
		);
	}//end testEveryBoundClockReachesTheCalendar()

	/**
	 * A clock nothing declares is not bound, and nothing is written.
	 *
	 * @return void
	 */
	public function testAnUndeclaredClockIsNotBound(): void {
		$this->termService->expects(self::never())->method('saveTermInstance');

		self::assertNull(
			$this->service->bindLeadTime(
				caseId: 'c1',
				kind: TermKind::INTERNAL,
				days: 0,
				start: new DateTimeImmutable('2026-09-15'),
			)
		);
	}//end testAnUndeclaredClockIsNotBound()

	/**
	 * A term naming a calendar that does not resolve refuses, and names it.
	 *
	 * The refusal is the whole point: answering on a DIFFERENT calendar is how
	 * a statutory date comes out wrong with nothing on screen to say so.
	 *
	 * @return void
	 */
	public function testAMissingCalendarRefusesRatherThanGuesses(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);

		$timers = new TermijnTimerService(
			settingsService: $settings,
			logger: new NullLogger(),
			dates: $this->caseDates(),
			fallbackCalendar: new WorkingDayCalculator(),
			calendarGuard: new TermCalendarGuard(logger: new NullLogger()),
		);

		try {
			$timers->rollTermEnd(
				date: new DateTimeImmutable('2026-09-15'),
				roll: true,
				calendarSlug: 'gemeente-rotterdam',
			);
			self::fail('A term naming an unresolvable calendar has to refuse.');
		} catch (RefusedException $refusal) {
			self::assertSame('term-calendar-unresolved', $refusal->getRule());
			self::assertStringContainsString('gemeente-rotterdam', $refusal->getSentence());
		}
	}//end testAMissingCalendarRefusesRatherThanGuesses()

	/**
	 * A term naming NO calendar keeps the documented local fallback.
	 *
	 * The control that separates the refusal above from "the engine is simply
	 * absent". Without it, the refusal could be firing on every install with no
	 * OpenRegister and the test above would still be green.
	 *
	 * @return void
	 */
	public function testATermNamingNoCalendarStillFallsBack(): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getOpenRegisterClass')->willReturn(null);

		$timers = new TermijnTimerService(
			settingsService: $settings,
			logger: new NullLogger(),
			dates: $this->caseDates(),
			fallbackCalendar: new WorkingDayCalculator(),
			calendarGuard: new TermCalendarGuard(logger: new NullLogger()),
		);

		// 19 September 2026 is a Saturday, so the local calendar rolls it.
		$rolled = $timers->rollTermEnd(date: new DateTimeImmutable('2026-09-19'), roll: true);

		self::assertSame('2026-09-21', $rolled->format('Y-m-d'));
	}//end testATermNamingNoCalendarStillFallsBack()

	/**
	 * One term instance row.
	 *
	 * @param string $kind The kind it carries.
	 * @param string $end Its current end date.
	 * @param string $status Its lifecycle status.
	 *
	 * @return array<string, mixed> The row.
	 */
	private function instance(string $kind, string $end, string $status = 'lopend'): array {
		return [
			'id' => ($kind . '-1'),
			'case' => 'c1',
			'kind' => $kind,
			'startDate' => '2026-09-01T00:00:00+00:00',
			'endDateCalculated' => $end,
			'endDateCurrent' => $end,
			'status' => $status,
		];
	}//end instance()
}//end class
