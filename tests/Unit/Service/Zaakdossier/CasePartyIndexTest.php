<?php

/**
 * CasePartyIndex unit tests.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Zaakdossier\CasePartyIndex;
use PHPUnit\Framework\TestCase;

/**
 * The parties of a case, matched by every way a submitted value may name one.
 *
 * @covers \OCA\Dossiq\Service\Zaakdossier\CasePartyIndex
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class CasePartyIndexTest extends TestCase {

	/**
	 * Two parties: one with a party uuid and an address, one known by contact uid only.
	 *
	 * @return CasePartyIndex
	 */
	private function index(): CasePartyIndex {
		return new CasePartyIndex(
			parties: [
				['partyUuid' => 'p-1', 'contactUid' => 'c-1', 'email' => 'Sanne@Example.nl'],
				['contactUid' => 'c-2', 'email' => 'sanne@example.nl'],
				'not a party',
			]
		);
	}//end index()

	/**
	 * A uuid, a contact uid, an address in any casing and an option object all name their party; the first holder wins.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testAValueNamesThePartyHoldingIt(): void {
		$index = $this->index();

		$this->assertSame(expected: 'p-1', actual: $index->match(value: 'p-1'));
		$this->assertSame(expected: 'p-1', actual: $index->match(value: ' c-1 '));
		$this->assertSame(expected: 'p-1', actual: $index->match(value: 'SANNE@example.NL'));
		$this->assertSame(expected: 'c-2', actual: $index->match(value: ['label' => 'Piet', 'contactUid' => 'c-2']));
		$this->assertSame(expected: '', actual: $index->match(value: 'nobody'));
		$this->assertSame(expected: '', actual: $index->match(value: null));
	}//end testAValueNamesThePartyHoldingIt()

	/**
	 * Recipients come as a list, one value or one option object; each party once, unmatched ones left out.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function testRecipientsAreMatchedEachOnce(): void {
		$index = $this->index();

		$this->assertSame(expected: ['p-1', 'c-2'], actual: $index->matchAll(values: ['p-1', 'c-1', 'c-2', 'nobody']));
		$this->assertSame(expected: ['c-2'], actual: $index->matchAll(values: 'c-2'));
		$this->assertSame(expected: ['p-1'], actual: $index->matchAll(values: ['partyUuid' => 'p-1']));
		$this->assertSame(expected: [], actual: $index->matchAll(values: ''));
		$this->assertSame(expected: 'c-2', actual: $index->identifierOf(party: ['partyUuid' => ' ', 'contactUid' => 'c-2']));
	}//end testRecipientsAreMatchedEachOnce()
}//end class
