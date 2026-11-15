<?php

/**
 * The field-role ledger on its own: which case type owns which grant, and what the properties end up carrying.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\FieldRoleLedger;
use OCA\Dossiq\Service\Access\FieldRoleRuleDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Access\FieldRoleLedger
 * @uses   \OCA\Dossiq\Service\Access\FieldRoleRuleDeclaration
 */
class FieldRoleLedgerTest extends TestCase {

	/**
	 * The ledger under test, over the real grant union.
	 *
	 * @return FieldRoleLedger
	 */
	private function ledger(): FieldRoleLedger {
		return new FieldRoleLedger(new FieldRoleRuleDeclaration());
	}//end ledger()

	/**
	 * A case type's entry replaces its old one, and an empty one withdraws it.
	 *
	 * @return void
	 */
	public function testACaseTypesEntryIsReplacedOrWithdrawn(): void {
		$ledger = ['ct-a' => ['bsn' => ['read' => ['g1']]], 'ct-b' => ['iban' => ['read' => ['g2']]]];

		self::assertSame(
			['ct-b' => ['iban' => ['read' => ['g2']]], 'ct-a' => ['bsn' => ['read' => ['g3']]]],
			$this->ledger()->ledgerWith(ledger: $ledger, caseTypeId: 'ct-a', own: ['bsn' => ['read' => ['g3']]])
		);
		self::assertSame(
			['ct-b' => ['iban' => ['read' => ['g2']]]],
			$this->ledger()->ledgerWith(ledger: $ledger, caseTypeId: 'ct-a', own: [])
		);
		self::assertSame($ledger, $this->ledger()->ledgerWith(ledger: $ledger, caseTypeId: '', own: ['x' => []]));
	}//end testACaseTypesEntryIsReplacedOrWithdrawn()

	/**
	 * The register base and every case type's grants are united per verb, without duplicates.
	 *
	 * @return void
	 */
	public function testTheBaseAndEveryCaseTypeAreUnitedPerVerb(): void {
		$properties = ['bsn' => ['type' => 'string'], 'name' => ['type' => 'string']];
		$base = ['bsn' => ['read' => ['admin']]];
		$ledger = [
			'ct-a' => ['bsn' => ['read' => ['admin', 'privacy'], 'update' => ['privacy']]],
			'ct-b' => ['bsn' => ['read' => ['social']]],
		];

		$result = $this->ledger()->propertiesWith(properties: $properties, base: $base, ledger: $ledger);

		self::assertSame(
			['read' => ['admin', 'privacy', 'social'], 'update' => ['privacy']],
			$result['bsn']['authorization']
		);
		self::assertSame(['type' => 'string'], $result['name'], 'A field nobody rules on is untouched.');
	}//end testTheBaseAndEveryCaseTypeAreUnitedPerVerb()

	/**
	 * Malformed ledger entries, blocks, verbs and held grants are skipped or read as empty.
	 *
	 * @return void
	 */
	public function testMalformedPartsAreSkipped(): void {
		$properties = ['bsn' => ['type' => 'string']];
		$base = ['bsn' => ['read' => 'not-a-list']];
		$ledger = [
			'broken' => 'not-an-entry',
			'ct-a' => ['bsn' => ['read' => ['privacy'], 'update' => 'not-a-list'], 'iban' => 'not-a-block'],
		];

		$result = $this->ledger()->propertiesWith(properties: $properties, base: $base, ledger: $ledger);

		self::assertSame(['read' => ['privacy']], $result['bsn']['authorization']);
		self::assertArrayNotHasKey('iban', $result, 'A rule on an undeclared property is not published.');
	}//end testMalformedPartsAreSkipped()

	/**
	 * A field only a withdrawn rule owned loses its authorization; one still owned elsewhere keeps it.
	 *
	 * @return void
	 */
	public function testAWithdrawnFieldIsClearedUnlessStillOwned(): void {
		$properties = [
			'bsn' => ['type' => 'string', 'authorization' => ['read' => ['privacy']]],
			'iban' => ['type' => 'string', 'authorization' => ['read' => ['finance']]],
			'note' => 'not-a-property',
		];
		$previous = [
			'ct-a' => ['bsn' => ['read' => ['privacy']], 'iban' => ['read' => ['finance']], 'note' => ['read' => ['x']]],
			'ignored' => 'not-an-entry',
		];
		$ledger = ['ct-b' => ['iban' => ['read' => ['finance']]]];

		$result = $this->ledger()->propertiesWith(properties: $properties, base: [], ledger: $ledger, previous: $previous);

		self::assertSame(['type' => 'string'], $result['bsn']);
		self::assertSame(['read' => ['finance']], $result['iban']['authorization']);
		self::assertSame('not-a-property', $result['note']);
	}//end testAWithdrawnFieldIsClearedUnlessStillOwned()

	/**
	 * The register's own grant survives a withdrawal of the same field.
	 *
	 * @return void
	 */
	public function testTheBaseOutlivesAWithdrawal(): void {
		$properties = ['bsn' => ['type' => 'string', 'authorization' => ['read' => ['admin', 'privacy']]]];

		$result = $this->ledger()->propertiesWith(
			properties: $properties,
			base: ['bsn' => ['read' => ['admin']]],
			ledger: [],
			previous: ['ct-a' => ['bsn' => ['read' => ['privacy']]]]
		);

		self::assertSame(['read' => ['admin']], $result['bsn']['authorization']);
	}//end testTheBaseOutlivesAWithdrawal()
}//end class
