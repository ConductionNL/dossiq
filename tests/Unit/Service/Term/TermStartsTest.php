<?php

/**
 * TermStarts Unit Tests
 *
 * A term starts under the resolution's definition, else the case type's
 * active one, and is refused without either; the stored instance is a
 * running statutory term with its end counted by the definition and the
 * rule that produced it; a store that answers nothing is refused.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Term
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

namespace OCA\Dossiq\Tests\Unit\Service\Term;

use DateTimeImmutable;
use OCA\Dossiq\Exception\NoTermijnDefinitieException;
use OCA\Dossiq\Service\Termijn\TermDefinitions;
use OCA\Dossiq\Service\Termijn\TermInstanceStore;
use OCA\Dossiq\Service\Term\TermStarts;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for TermStarts.
 *
 * @covers \OCA\Dossiq\Service\Term\TermStarts
 * @uses   \OCA\Dossiq\Service\TermStart
 */
final class TermStartsTest extends TestCase {

	/**
	 * Definitions answering the given active definition and an end date eight weeks on.
	 *
	 * @param array<string, mixed>|null $active The case type's active definition.
	 *
	 * @return TermDefinitions
	 */
	private function definitions(?array $active): TermDefinitions {
		$definitions = $this->createMock(originalClassName: TermDefinitions::class);
		$definitions->method('activeFor')->willReturn($active);
		$definitions->method('endDateFor')->willReturnCallback(
			static fn (DateTimeImmutable $start, int $days, array $definitie): DateTimeImmutable => $start->modify('+' . $days . ' days')
		);
		return $definitions;
	}//end definitions()

	/**
	 * The case type's active definition decides the duration when no resolution was made.
	 *
	 * @return void
	 */
	public function testTheCaseTypesActiveDefinitionStartsTheTerm(): void {
		$starts = new TermStarts(
			definitions: $this->definitions(active: ['id' => 'def-1', 'standardDurationDays' => 56]),
			store: $this->createMock(originalClassName: TermInstanceStore::class),
		);
		$day = new DateTimeImmutable('2026-11-02T09:00:00+01:00');

		$start = $starts->begin(caseId: 'case-1', start: ['caseType' => 'omgevingsvergunning', 'startDate' => $day, 'resolution' => null]);

		$this->assertSame(expected: 'case-1', actual: $start->caseId);
		$this->assertSame(expected: 'omgevingsvergunning', actual: $start->caseType);
		$this->assertSame(expected: $day, actual: $start->startDate);
		$this->assertSame(expected: ['id' => 'def-1', 'standardDurationDays' => 56], actual: $start->definitie);
		$this->assertSame(expected: 56, actual: $start->durationDays);
	}//end testTheCaseTypesActiveDefinitionStartsTheTerm()

	/**
	 * A resolution's definition wins over the case type's, and no definition at all is refused.
	 *
	 * @return void
	 */
	public function testAResolutionWinsAndNoDefinitionIsRefused(): void {
		$starts = new TermStarts(
			definitions: $this->definitions(active: null),
			store: $this->createMock(originalClassName: TermInstanceStore::class),
		);

		$start = $starts->begin(
			caseId: 'case-1',
			start: ['caseType' => 'x', 'startDate' => null, 'resolution' => ['definition' => ['id' => 'def-r', 'standardDurationDays' => 14]]]
		);
		$this->assertSame(expected: 14, actual: $start->durationDays);
		$this->assertInstanceOf(expected: DateTimeImmutable::class, actual: $start->startDate);

		$this->expectException(exception: NoTermijnDefinitieException::class);
		$this->expectExceptionMessage(message: 'No active TermijnDefinitie configured for zaaktype "x" (REQ-TERM-001-A)');
		$starts->begin(caseId: 'case-1', start: ['caseType' => 'x', 'startDate' => null, 'resolution' => null]);
	}//end testAResolutionWinsAndNoDefinitionIsRefused()

	/**
	 * The stored instance is a running statutory term with its end and the rule that produced it.
	 *
	 * @return void
	 */
	public function testTheInstanceIsStoredAsARunningStatutoryTerm(): void {
		$store = $this->createMock(originalClassName: TermInstanceStore::class);
		$store->expects($this->once())->method('save')
			->with(
				'termijn_instance_schema',
				[
					'case' => 'case-1',
					'deadlineDefinition' => 'def-r',
					'kind' => 'statutory',
					'startDate' => '2026-11-02T09:00:00+01:00',
					'endDateCalculated' => '2026-11-16',
					'endDateCurrent' => '2026-11-16',
					'status' => 'lopend',
					'countExtensions' => 0,
					'notificatiesVerstuurd' => [],
					'resolvedFrom' => 'organisation',
					'resolutionSnapshot' => ['rule' => 'r1'],
				]
			)
			->willReturn(['id' => 'inst-1']);
		$starts = new TermStarts(definitions: $this->definitions(active: null), store: $store);
		$start = $starts->begin(
			caseId: 'case-1',
			start: [
				'caseType' => 'x',
				'startDate' => new DateTimeImmutable('2026-11-02T09:00:00+01:00'),
				'resolution' => [
					'definition' => ['id' => 'def-r', 'standardDurationDays' => 14],
					'resolvedFrom' => 'organisation',
					'snapshot' => ['rule' => 'r1'],
				],
			]
		);

		$this->assertSame(expected: ['id' => 'inst-1'], actual: $starts->store(start: $start));
	}//end testTheInstanceIsStoredAsARunningStatutoryTerm()

	/**
	 * A store that answers nothing is refused, naming the case.
	 *
	 * @return void
	 */
	public function testAnUnsavedInstanceIsRefused(): void {
		$store = $this->createMock(originalClassName: TermInstanceStore::class);
		$store->method('save')->willReturn(null);
		$starts = new TermStarts(definitions: $this->definitions(active: ['id' => 'def-1', 'standardDurationDays' => 1]), store: $store);
		$start = $starts->begin(caseId: 'case-9', start: ['caseType' => 'x', 'startDate' => null, 'resolution' => null]);

		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Failed to persist TermijnInstance for zaak "case-9" (persistence unavailable)');
		$starts->store(start: $start);
	}//end testAnUnsavedInstanceIsRefused()
}//end class
