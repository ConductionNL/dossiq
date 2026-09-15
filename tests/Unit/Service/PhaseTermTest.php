<?php

/**
 * A phase carries its own clock, and it never moves the case's.
 *
 * The overrunning phase is the case this whole cluster exists for: twenty days
 * into a fourteen day phase, with forty days of case term left, the phase reads
 * overdue and the case reads on time. An implementation that had let the phase
 * eat into the case term would fail the second half of that assertion and pass
 * the first, which is why both are asserted.
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
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\ChainTermSplitter;
use OCA\Dossiq\Service\PhaseTermService;
use OCA\Dossiq\Service\TermDeclarationReader;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\TermKind;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * REQ-TERM-061 and REQ-TERM-065: a phase clock, and the chain split behind it.
 *
 * @covers \OCA\Dossiq\Service\PhaseTermService
 * @covers \OCA\Dossiq\Service\CaseTermsService
 */
class PhaseTermTest extends TestCase {
	use BindsTermFixtures;

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
	 * Every patch the store was handed, keyed by instance id.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $patches = [];

	/**
	 * The service under test.
	 *
	 * @var PhaseTermService
	 */
	private PhaseTermService $phases;

	/**
	 * The reading side.
	 *
	 * @var CaseTermsService
	 */
	private CaseTermsService $terms;

	/**
	 * Wire both services against one mocked store.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->termService = $this->createMock(TermijnService::class);
		$this->declarations = $this->createMock(TermDeclarationReader::class);
		$timers = $this->createMock(TermijnTimerService::class);
		$timers->method('rollTermEndFor')->willReturnArgument(0);

		$this->written = [];
		$this->patches = [];

		$this->termService->method('saveTermInstance')->willReturnCallback(
			function (array $instance): array {
				$instance['id'] = ('written-' . count($this->written));
				$this->written[] = $instance;

				return $instance;
			}
		);
		$this->termService->method('instancesForCase')->willReturnCallback(
			fn (string $caseId): array => $this->instances
		);
		$this->termService->method('updateTermijnInstance')->willReturnCallback(
			function (string $id, array $patch): array {
				$this->patches[$id] = $patch;

				return $patch;
			}
		);

		$this->terms = new CaseTermsService(
			termService: $this->termService,
			declarations: $this->declarations,
			timers: $timers,
			logger: new NullLogger(),
		);

		$this->phases = new PhaseTermService(
			termService: $this->termService,
			terms: $this->terms,
			declarations: $this->declarations,
			splitter: new ChainTermSplitter(),
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * An ontvankelijkheidstoets declaring 14 days starts a clock 14 days long.
	 *
	 * @return void
	 */
	public function testAPhaseWithItsOwnTermStartsItsOwnClock(): void {
		$this->declarations->method('forStatusType')->willReturn(
			['termDays' => 14, 'share' => 0.0, 'order' => 1, 'name' => 'Ontvankelijkheidstoets']
		);

		$started = $this->phases->enterPhase(
			caseId: 'c1',
			caseTypeId: 'ct1',
			statusTypeId: 'st1',
			when: new DateTimeImmutable('2026-09-01'),
		);

		self::assertNotNull($started);
		self::assertSame(TermKind::PHASE, $started['kind']);
		self::assertSame('st1', $started['statusType']);
		self::assertSame('2026-09-15', $started['endDateCurrent']);
	}//end testAPhaseWithItsOwnTermStartsItsOwnClock()

	/**
	 * Entering a phase stops the clock of the phase the case left.
	 *
	 * @return void
	 */
	public function testEnteringAPhaseStopsThePreviousPhaseClock(): void {
		$this->instances = [
			array_merge($this->instanceOf(kind: TermKind::PHASE, end: '2026-09-10'), ['id' => 'p1', 'statusType' => 'st0']),
		];
		$this->declarations->method('forStatusType')->willReturn(
			['termDays' => 14, 'share' => 0.0, 'order' => 2, 'name' => 'Beoordeling']
		);

		$this->phases->enterPhase(
			caseId: 'c1',
			caseTypeId: 'ct1',
			statusTypeId: 'st1',
			when: new DateTimeImmutable('2026-09-15'),
		);

		self::assertArrayHasKey('p1', $this->patches);
		self::assertSame(PhaseTermService::STATUS_COMPLETED, $this->patches['p1']['status']);
		self::assertSame('2026-09-15', $this->patches['p1']['voltooiDatum']);
	}//end testEnteringAPhaseStopsThePreviousPhaseClock()

	/**
	 * The clock of the phase being entered is left alone.
	 *
	 * @return void
	 */
	public function testTheClockOfThePhaseBeingEnteredIsLeftRunning(): void {
		$this->instances = [
			array_merge($this->instanceOf(kind: TermKind::PHASE, end: '2026-09-30'), ['id' => 'p1', 'statusType' => 'st1']),
		];
		$this->declarations->method('forStatusType')->willReturn(
			['termDays' => 14, 'share' => 0.0, 'order' => 1, 'name' => 'Beoordeling']
		);

		$this->phases->stopRunningPhases(
			caseId: 'c1',
			when: new DateTimeImmutable('2026-09-15'),
			except: 'st1',
		);

		self::assertSame([], $this->patches);
	}//end testTheClockOfThePhaseBeingEnteredIsLeftRunning()

	/**
	 * An overrunning phase reads overdue while the case term reads on time.
	 *
	 * @return void
	 */
	public function testAnOverrunningPhaseIsVisibleBeforeTheCaseIs(): void {
		$this->instances = [
			$this->instanceOf(kind: TermKind::STATUTORY, end: '2026-10-25'),
			$this->instanceOf(kind: TermKind::PHASE, end: '2026-09-09', start: '2026-08-26T00:00:00+00:00'),
		];

		$read = $this->terms->termsForCase(caseId: 'c1', now: new DateTimeImmutable('2026-09-15'));
		$byKind = array_column($read, null, 'kind');

		self::assertTrue($byKind[TermKind::PHASE]['overdue'], 'Twenty days into a fourteen day phase.');
		self::assertFalse($byKind[TermKind::STATUTORY]['overdue'], 'Forty days of case term left.');
		self::assertSame(40, $byKind[TermKind::STATUTORY]['daysLeft']);
	}//end testAnOverrunningPhaseIsVisibleBeforeTheCaseIs()

	/**
	 * Ending a phase never touches the case term.
	 *
	 * @return void
	 */
	public function testAPhaseNeverExtendsTheCaseTerm(): void {
		$this->instances = [
			array_merge($this->instanceOf(kind: TermKind::STATUTORY, end: '2026-10-01'), ['id' => 's1']),
			array_merge($this->instanceOf(kind: TermKind::PHASE, end: '2026-11-01'), ['id' => 'p1', 'statusType' => 'st0']),
		];

		$this->phases->stopRunningPhases(caseId: 'c1', when: new DateTimeImmutable('2026-09-15'));

		self::assertArrayHasKey('p1', $this->patches);
		self::assertArrayNotHasKey('s1', $this->patches, 'Nothing here may write to a statutory instance.');
	}//end testAPhaseNeverExtendsTheCaseTerm()

	/**
	 * A phase with no clock of its own, in a chain, gets its share of the chain.
	 *
	 * @return void
	 */
	public function testAPhaseInAChainGetsItsShare(): void {
		$this->declarations->method('forStatusType')->willReturn(
			['termDays' => 0, 'share' => 25.0, 'order' => 1, 'name' => 'Stap 1']
		);
		$this->declarations->method('forCaseType')->willReturn($this->declared(['chainTermDays' => 40]));
		$this->declarations->method('phasesOf')->willReturn($this->fourSteps());

		self::assertSame(
			10,
			$this->phases->daysForPhase(caseId: 'c1', caseTypeId: 'ct1', statusTypeId: 'st1')
		);
	}//end testAPhaseInAChainGetsItsShare()

	/**
	 * A step that overran shrinks the one after it, and the chain end holds.
	 *
	 * @return void
	 */
	public function testAStepThatOverranShrinksTheNextOne(): void {
		$this->instances = [
			array_merge(
				$this->instanceOf(kind: TermKind::PHASE, end: '2026-09-10', status: 'completed', start: '2026-09-01T00:00:00+00:00'),
				['id' => 'p1', 'statusType' => 'st1', 'voltooiDatum' => '2026-09-16']
			),
		];
		$this->declarations->method('forStatusType')->willReturn(
			['termDays' => 0, 'share' => 25.0, 'order' => 2, 'name' => 'Stap 2']
		);
		$this->declarations->method('forCaseType')->willReturn($this->declared(['chainTermDays' => 40]));
		$this->declarations->method('phasesOf')->willReturn($this->fourSteps());

		$plan = $this->phases->chainPlan(caseId: 'c1', caseTypeId: 'ct1', chainTermDays: 40, statusTypeId: 'st2');

		self::assertSame(25, $plan['remainingDays'], 'The first step used fifteen of the forty.');
		self::assertSame(25, array_sum($plan['steps']), 'The chain end did not move.');
		self::assertSame(8, $plan['days']);
	}//end testAStepThatOverranShrinksTheNextOne()

	/**
	 * A phase nothing declares starts no clock and writes nothing.
	 *
	 * @return void
	 */
	public function testAPhaseNothingDeclaresStartsNoClock(): void {
		$this->declarations->method('forStatusType')->willReturn(
			['termDays' => 0, 'share' => 0.0, 'order' => 1, 'name' => 'Ontvangen']
		);
		$this->declarations->method('forCaseType')->willReturn($this->declared([]));

		self::assertNull(
			$this->phases->enterPhase(caseId: 'c1', caseTypeId: 'ct1', statusTypeId: 'st1')
		);
		self::assertSame([], $this->written);
	}//end testAPhaseNothingDeclaresStartsNoClock()

	/**
	 * Four equal steps of a chain.
	 *
	 * @return array<int, array<string, mixed>> The phases, in order.
	 */
	private function fourSteps(): array {
		return [
			['id' => 'st1', 'termDays' => 0, 'share' => 25.0, 'order' => 1, 'name' => 'Stap 1'],
			['id' => 'st2', 'termDays' => 0, 'share' => 25.0, 'order' => 2, 'name' => 'Stap 2'],
			['id' => 'st3', 'termDays' => 0, 'share' => 25.0, 'order' => 3, 'name' => 'Stap 3'],
			['id' => 'st4', 'termDays' => 0, 'share' => 25.0, 'order' => 4, 'name' => 'Stap 4'],
		];
	}//end fourSteps()
}//end class
