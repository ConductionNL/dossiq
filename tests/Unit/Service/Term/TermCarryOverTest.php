<?php

/**
 * An imported case's statutory term lands on the date the source system
 * already told the requester, extended and suspended where the source was.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Service\Term
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/case-record-import/spec.md#requirement-an-imported-records-running-term-is-carried-onto-the-case-req-cri-003
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\Dossiq\Service\Term\TermCarryOver;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Term\TermCarryOver
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 */
class TermCarryOverTest extends TestCase {

	use MakesCaseDateNormaliser;

	/**
	 * The term service double.
	 *
	 * @var TermijnService&MockObject
	 */
	private TermijnService $terms;

	/**
	 * The timer service double.
	 *
	 * @var TermijnTimerService&MockObject
	 */
	private TermijnTimerService $timers;

	/**
	 * Fresh doubles per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->terms = $this->createMock(TermijnService::class);
		$this->timers = $this->createMock(TermijnTimerService::class);
	}//end setUp()

	/**
	 * The class under test.
	 *
	 * @return TermCarryOver
	 */
	private function term(): TermCarryOver {
		return new TermCarryOver(terms: $this->terms, timers: $this->timers, dates: $this->caseDates());
	}//end term()

	/**
	 * The fresh P28D instance the case-created listener binds, behind a phase term.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function freshInstances(): array {
		return [
			['id' => 'phase-1', 'kind' => 'phase', 'status' => 'lopend', 'endDateCurrent' => '2026-03-10'],
			[
				'id' => 'term-1',
				'kind' => 'statutory',
				'status' => 'lopend',
				'startDate' => '2026-02-09',
				'endDateCurrent' => '2026-03-09',
				'engineTimerId' => 'fresh-timer',
				'case' => 'case-1',
			],
		];
	}//end freshInstances()

	/**
	 * A source term state.
	 *
	 * @param bool   $open       Open or closed.
	 * @param bool   $suspended  Suspended.
	 * @param string $deadline   The source's end date.
	 * @param int    $extensions The source's extension count.
	 * @param string $endDate    The day a closed source closed.
	 *
	 * @return array<string, mixed>
	 */
	private function mapped(bool $open, bool $suspended, string $deadline, int $extensions = 0, string $endDate = ''): array {
		$state = TermCarryOver::STATE_RUNNING;
		if ($suspended === true) {
			$state = TermCarryOver::STATE_SUSPENDED;
		}

		if ($open === false) {
			$state = TermCarryOver::STATE_CLOSED;
		}

		return ['state' => $state, 'endDate' => $deadline, 'extensions' => $extensions, 'extensionReason' => '', 'closedOn' => $endDate];
	}//end mapped()

	/**
	 * A request that was never extended or suspended keeps the fresh term and its timer.
	 *
	 * @return void
	 */
	public function testARunningRequestOnItsOwnDateKeepsTheFreshTimer(): void {
		$this->terms->method('instancesForCase')->willReturn($this->freshInstances());
		$this->timers->expects(self::never())->method('cancelForInstance');
		$this->timers->expects(self::never())->method('armBeslistermijn');
		$this->terms->expects(self::never())->method('updateTermijnInstance');

		$answer = $this->term()->carry(caseId: 'case-1', term: $this->mapped(open: true, suspended: false, deadline: '2026-03-09'), definitionSlug: 'type-a', extensionDays: 14);

		self::assertSame(['outcome' => TermCarryOver::KEPT, 'instance' => 'term-1', 'timer' => 'fresh-timer'], $answer);
	}//end testARunningRequestOnItsOwnDateKeepsTheFreshTimer()

	/**
	 * An extended request moves its term to the source's later end, re-armed with the breach mark.
	 *
	 * @return void
	 */
	public function testAnExtendedRequestMovesItsTermToTheSourcesEnd(): void {
		$this->terms->method('instancesForCase')->willReturn($this->freshInstances());
		$this->terms->method('getTermijnDefinitie')->with('type-a')->willReturn(['countExtensions' => 1, 'legalBasis' => 'Basis 1']);
		$this->timers->expects(self::once())->method('cancelForInstance')->with('term-1', self::anything());

		$patches = [];
		$this->terms->method('updateTermijnInstance')->willReturnCallback(
			function (string $termInstanceId, array $patch) use (&$patches): array {
				$patches[] = $patch;
				return array_merge($this->freshInstances()[1], $patch);
			}
		);
		$this->timers->expects(self::once())->method('armBeslistermijn')
			->with(
				self::callback(static fn (array $instance): bool => $instance['endDateCurrent'] === '2026-04-06' && $instance['engineTimerId'] === ''),
				['countExtensions' => 1, 'legalBasis' => 'Basis 1']
			)
			->willReturn('carried-timer');
		$this->timers->expects(self::never())->method('suspendBeslistermijn');
		$this->terms->expects(self::once())->method('recordEvent')
			->with('term-1', 'verdaging', 'Basis 1', 'Zienswijzen', 14);

		$mapped = $this->mapped(open: true, suspended: false, deadline: '2026-04-06', extensions: 1);
		$mapped['extensionReason'] = 'Zienswijzen';
		$answer = $this->term()->carry(caseId: 'case-1', term: $mapped, definitionSlug: 'type-a', extensionDays: 14);

		self::assertSame(['outcome' => TermCarryOver::CARRIED, 'instance' => 'term-1', 'timer' => 'carried-timer'], $answer);
		self::assertSame(['countExtensions' => 1, 'status' => 'verlengd', 'engineTimerId' => '', 'endDateCurrent' => '2026-04-06'], $patches[0]);
		self::assertSame(['engineTimerId' => 'carried-timer', 'timerBreachesAfterLastDay' => true], $patches[1]);
	}//end testAnExtendedRequestMovesItsTermToTheSourcesEnd()

	/**
	 * A second run over a term it already carried finds it carried, and arms nothing.
	 *
	 * @return void
	 */
	public function testATermCarriedBeforeIsKept(): void {
		$carried = $this->freshInstances();
		$carried[1] = array_merge($carried[1], ['status' => 'verlengd', 'countExtensions' => 1, 'endDateCurrent' => '2026-04-06', 'engineTimerId' => 'carried-timer']);
		$this->terms->method('instancesForCase')->willReturn($carried);
		$this->timers->expects(self::never())->method('armBeslistermijn');
		$this->terms->expects(self::never())->method('recordEvent');

		$answer = $this->term()->carry(caseId: 'case-1', term: $this->mapped(open: true, suspended: false, deadline: '2026-04-06', extensions: 1), definitionSlug: 'type-a', extensionDays: 14);

		self::assertSame(TermCarryOver::KEPT, $answer['outcome']);
	}//end testATermCarriedBeforeIsKept()

	/**
	 * A request waiting on the requester is paused and its new timer suspended at once.
	 *
	 * @return void
	 */
	public function testASuspendedRequestIsPausedAndItsTimerSuspended(): void {
		$this->terms->method('instancesForCase')->willReturn($this->freshInstances());
		$this->terms->method('getTermijnDefinitie')->willReturn(null);
		$this->terms->method('updateTermijnInstance')->willReturnCallback(
			fn (string $termInstanceId, array $patch): array => array_merge($this->freshInstances()[1], $patch)
		);
		$this->timers->method('armBeslistermijn')->with(self::callback(static fn (array $i): bool => $i['status'] === 'paused'), [])->willReturn('t2');
		$this->timers->expects(self::once())->method('suspendBeslistermijn')
			->with(self::callback(static fn (array $instance): bool => $instance['engineTimerId'] === 't2'), self::isType('string'), null)
			->willReturn(true);

		$answer = $this->term()->carry(caseId: 'case-1', term: $this->mapped(open: true, suspended: true, deadline: '2026-03-09'), definitionSlug: 'type-a', extensionDays: 14);

		self::assertSame(TermCarryOver::CARRIED, $answer['outcome']);
	}//end testASuspendedRequestIsPausedAndItsTimerSuspended()

	/**
	 * A decided request completes the fresh term on its decision date; nothing is armed.
	 *
	 * @return void
	 */
	public function testAClosedRequestCompletesTheTerm(): void {
		$this->terms->method('instancesForCase')->willReturn($this->freshInstances());
		$this->terms->expects(self::once())->method('markTermijnCompleted')
			->with('term-1', self::callback(static fn ($d): bool => $d !== null && $d->format('Y-m-d') === '2026-02-05'), '', self::isType('string'));
		$this->timers->expects(self::never())->method('armBeslistermijn');

		$answer = $this->term()->carry(caseId: 'case-1', term: $this->mapped(open: false, suspended: false, deadline: '2026-02-09', endDate: '2026-02-05'), definitionSlug: 'type-a', extensionDays: 14);

		self::assertSame(['outcome' => TermCarryOver::COMPLETED, 'instance' => 'term-1', 'timer' => ''], $answer);
	}//end testAClosedRequestCompletesTheTerm()

	/**
	 * An engine that refuses the new timer is not armed, and says so.
	 *
	 * @return void
	 */
	public function testARefusedTimerIsNotArmed(): void {
		$this->terms->method('instancesForCase')->willReturn($this->freshInstances());
		$this->terms->method('updateTermijnInstance')->willReturn(null);
		$this->timers->method('armBeslistermijn')->willReturn(null);
		$this->timers->expects(self::never())->method('suspendBeslistermijn');

		$answer = $this->term()->carry(caseId: 'case-1', term: $this->mapped(open: true, suspended: true, deadline: '2026-04-06', extensions: 1), definitionSlug: 'type-a', extensionDays: 14);

		self::assertSame(['outcome' => TermCarryOver::NOT_ARMED, 'instance' => 'term-1', 'timer' => ''], $answer);
	}//end testARefusedTimerIsNotArmed()

	/**
	 * No statutory term to carry (none bound, or the store is down) is missing, and moves nothing.
	 *
	 * @return void
	 */
	public function testNoStatutoryTermIsMissing(): void {
		$this->terms->method('instancesForCase')->willReturnOnConsecutiveCalls(
			[['id' => 'phase-1', 'kind' => 'phase', 'status' => 'lopend'], ['id' => 'old', 'kind' => 'statutory', 'status' => 'completed']],
			self::throwException(new RuntimeException('store down'))
		);
		$this->timers->expects(self::never())->method('cancelForInstance');
		$this->terms->expects(self::never())->method('markTermijnCompleted');

		$missing = ['outcome' => TermCarryOver::MISSING, 'instance' => '', 'timer' => ''];
		self::assertSame($missing, $this->term()->carry(caseId: 'case-1', term: $this->mapped(open: true, suspended: false, deadline: '2026-04-06'), definitionSlug: 'type-a', extensionDays: 14));
		self::assertSame($missing, $this->term()->carry(caseId: 'case-1', term: $this->mapped(open: false, suspended: false, deadline: ''), definitionSlug: 'type-a', extensionDays: 14));
	}//end testNoStatutoryTermIsMissing()
}//end class
