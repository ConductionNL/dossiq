<?php

/**
 * InheritedCaseDeadline Unit Tests
 *
 * The term a child case type inherits (none when it sets its own, has no
 * parent or names no type) and the deadline it implies: the start date plus
 * the term, rolled by the administered calendar when one is wired, and null
 * with a warning when the term is not a duration.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Listener\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\Contracts\CaseTypeResolverInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnTimerServiceInterface;
use OCA\Dossiq\Listener\Support\InheritedCaseDeadline;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit tests for InheritedCaseDeadline.
 *
 * @covers \OCA\Dossiq\Listener\Support\InheritedCaseDeadline
 */
class InheritedCaseDeadlineTest extends TestCase {

	/**
	 * A resolver whose chain and effective type are given.
	 *
	 * @param array<int, array<string, mixed>> $chain     The chain, the type itself first.
	 * @param array<string, mixed>             $effective The effective case type.
	 *
	 * @return CaseTypeResolverInterface
	 */
	private function resolver(array $chain, array $effective = []): CaseTypeResolverInterface {
		$resolver = $this->createMock(originalClassName: CaseTypeResolverInterface::class);
		$resolver->method('chainFor')->willReturn($chain);
		$resolver->method('effectiveCaseType')->willReturn($effective);
		return $resolver;
	}//end resolver()

	/**
	 * A silent child takes the effective term, trimmed; a child with its own term,
	 * a type without a parent or no type takes none.
	 *
	 * @return void
	 */
	public function testTheInheritedTermIsTheEffectiveOneOfASilentChildOnly(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$silent = new InheritedCaseDeadline(
			resolver: $this->resolver(
				chain: [['id' => 'child'], ['id' => 'parent', 'processingDeadline' => 'P6W']],
				effective: ['processingDeadline' => ' P6W ']
			),
			logger: $logger
		);
		$this->assertSame(expected: 'P6W', actual: $silent->inheritedTerm(caseTypeId: 'child'));
		$this->assertSame(expected: '', actual: $silent->inheritedTerm(caseTypeId: ''));

		$own = new InheritedCaseDeadline(
			resolver: $this->resolver(
				chain: [['id' => 'child', 'processingDeadline' => 'P2W'], ['id' => 'parent']],
				effective: ['processingDeadline' => 'P2W']
			),
			logger: $logger
		);
		$this->assertSame(expected: '', actual: $own->inheritedTerm(caseTypeId: 'child'));

		$orphan = new InheritedCaseDeadline(
			resolver: $this->resolver(chain: [['id' => 'only']], effective: ['processingDeadline' => 'P8W']),
			logger: $logger
		);
		$this->assertSame(expected: '', actual: $orphan->inheritedTerm(caseTypeId: 'only'));
	}//end testTheInheritedTermIsTheEffectiveOneOfASilentChildOnly()

	/**
	 * The deadline is the start date (its date part) plus the term; without a calendar nothing rolls.
	 *
	 * @return void
	 */
	public function testTheDeadlineIsTheStartDatePlusTheTerm(): void {
		$deadlines = new InheritedCaseDeadline(resolver: $this->resolver(chain: []), logger: $this->createMock(originalClassName: LoggerInterface::class));

		$this->assertSame(expected: '2026-12-10', actual: $deadlines->deadlineFrom(startDate: '2026-11-26T09:30:00+01:00', term: 'P2W'));
		$this->assertSame(
			expected: (new DateTimeImmutable('today'))->add(new \DateInterval('P1D'))->format('Y-m-d'),
			actual: $deadlines->deadlineFrom(startDate: '  ', term: 'P1D')
		);
	}//end testTheDeadlineIsTheStartDatePlusTheTerm()

	/**
	 * With a calendar wired, the end lands on the day it rolls to.
	 *
	 * @return void
	 */
	public function testTheCalendarRollsTheEnd(): void {
		$timers = $this->createMock(originalClassName: TermijnTimerServiceInterface::class);
		$timers->expects($this->once())->method('rollTermEndFor')
			->with($this->callback(callback: static fn (DateTimeImmutable $d): bool => $d->format('Y-m-d') === '2026-12-26'))
			->willReturn(new DateTimeImmutable('2026-12-28'));
		$deadlines = new InheritedCaseDeadline(
			resolver: $this->resolver(chain: []),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			timerService: $timers
		);

		$this->assertSame(expected: '2026-12-28', actual: $deadlines->deadlineFrom(startDate: '2026-11-26', term: 'P30D'));
	}//end testTheCalendarRollsTheEnd()

	/**
	 * A term that is not a duration gives no deadline and a warning naming both inputs.
	 *
	 * @return void
	 */
	public function testATermThatIsNotADurationGivesNoDeadline(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning')->with(
			'Dossiq: could not derive an inherited case deadline',
			$this->callback(callback: static fn (array $c): bool => $c['startDate'] === '2026-11-26' && $c['term'] === 'twelve weeks' && $c['error'] !== '')
		);
		$deadlines = new InheritedCaseDeadline(resolver: $this->resolver(chain: []), logger: $logger);

		$this->assertNull(actual: $deadlines->deadlineFrom(startDate: '2026-11-26', term: 'twelve weeks'));
	}//end testATermThatIsNotADurationGivesNoDeadline()
}//end class
