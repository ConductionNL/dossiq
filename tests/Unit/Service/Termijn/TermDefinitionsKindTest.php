<?php

/**
 * A service norm never arms the statutory term (REQ-TERM-065).
 *
 * `activeFor()` is what the statutory start falls back to when no resolution
 * was made, and what the rearm, the status clock and the Woo term read. It
 * ignored `kind`, so a case type whose only definition is a service norm got
 * that norm armed as its statutory term, the term a citizen is told about.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Service\Termijn\TermDefinitions;
use OCA\Dossiq\Service\TermKind;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Termijn\TermDefinitions
 * @uses \OCA\Dossiq\Service\TermKind
 */
class TermDefinitionsKindTest extends TestCase {

	/**
	 * Definitions whose store answers these rows for every case type.
	 *
	 * @param array<int, array<string, mixed>> $rows The active rows, newest first.
	 *
	 * @return TermDefinitions The definitions.
	 */
	private function definitionsAnswering(array $rows): TermDefinitions {
		$definitions = $this->createPartialMock(TermDefinitions::class, ['allActiveFor']);
		$definitions->method('allActiveFor')->willReturn($rows);

		return $definitions;
	}//end definitionsAnswering()

	/**
	 * Only a planned definition: no statutory term is answered.
	 *
	 * @return void
	 */
	public function testAPlannedDefinitionIsNotTheStatutoryTerm(): void {
		$definitions = $this->definitionsAnswering(
			[['id' => 'td-building-permit', 'kind' => TermKind::PLANNED, 'standardDurationDays' => 56]]
		);

		self::assertNull($definitions->activeFor(caseType: 'building-permit'));
	}//end testAPlannedDefinitionIsNotTheStatutoryTerm()

	/**
	 * A planned and a statutory definition: the statutory one is answered.
	 *
	 * @return void
	 */
	public function testTheStatutoryDefinitionIsAnsweredBesideAPlannedOne(): void {
		$definitions = $this->definitionsAnswering(
			[
				['id' => 'planned', 'kind' => TermKind::PLANNED, 'validFrom' => '2026-06-01'],
				['id' => 'statutory', 'kind' => TermKind::STATUTORY, 'validFrom' => '2026-01-01'],
			]
		);

		self::assertSame('statutory', $definitions->activeFor(caseType: 'klacht-behandeling')['id'] ?? null);
	}//end testTheStatutoryDefinitionIsAnsweredBesideAPlannedOne()

	/**
	 * A definition written before the kinds existed is statutory.
	 *
	 * @return void
	 */
	public function testADefinitionWithoutAKindIsStatutory(): void {
		$definitions = $this->definitionsAnswering([['id' => 'td-woo-verzoek', 'standardDurationDays' => 28]]);

		self::assertSame('td-woo-verzoek', $definitions->activeFor(caseType: 'woo-verzoek')['id'] ?? null);
		self::assertTrue(TermDefinitions::isStatutory(definitie: ['kind' => '']));
		self::assertTrue(TermDefinitions::isStatutory(definitie: ['kind' => 'something-new']));
		self::assertFalse(TermDefinitions::isStatutory(definitie: ['kind' => TermKind::INTERNAL]));
	}//end testADefinitionWithoutAKindIsStatutory()
}//end class
