<?php

/**
 * RecordSeal edge tests: the stored hash format stays stable, and a seal
 * whose members have the wrong types never verifies.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\FrozenRecord
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
 * @spec openspec/specs/frozen-record/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\FrozenRecord;

use DateTimeImmutable;
use OCA\Dossiq\Service\FrozenRecord\FreezeDefinition;
use OCA\Dossiq\Service\FrozenRecord\RecordSeal;
use PHPUnit\Framework\TestCase;

/**
 * Seals are stored on records and verified later, so the hash of a given
 * record must never change between releases.
 *
 * @covers \OCA\Dossiq\Service\FrozenRecord\RecordSeal
 * @uses   \OCA\Dossiq\Service\FrozenRecord\FreezeDefinition
 * @uses   \OCA\Dossiq\Service\FrozenRecord\CanonicalValue
 */
class RecordSealEdgeTest extends TestCase {

	/**
	 * An entry sealing a URL, an amount and a title.
	 *
	 * @return FreezeDefinition The entry.
	 */
	private function definition(): FreezeDefinition {
		return new FreezeDefinition(entry: ['schema' => 'besluit', 'frozenStatuses' => ['issued'], 'sealFields' => ['url', 'amount', 'title']]);
	}//end definition()

	/**
	 * The hash is SHA-256 over the sealed fields as JSON, keys sorted, slashes
	 * and non-ASCII letters unescaped and a float's zero fraction kept: the
	 * value a stored seal was made with.
	 *
	 * @return void
	 */
	public function testTheHashIsTheStoredCanonicalForm(): void {
		$seal = (new RecordSeal())->seal(
			definition: $this->definition(),
			record: ['url' => 'https://x.nl/a/b', 'amount' => 1.0, 'title' => 'Besluit één', 'notSealed' => 'x'],
			moment: new DateTimeImmutable(datetime: '2026-10-10T12:00:00+02:00')
		);

		$this->assertSame(
			expected: [
				'algorithm' => 'sha256',
				'hash' => 'aea1c70095bd9d6671e37988b85b76691f378e8eb1290731fb44687407996cb1',
				'fields' => ['url', 'amount', 'title'],
				'sealedAt' => '2026-10-10T12:00:00+02:00',
			],
			actual: $seal
		);
	}//end testTheHashIsTheStoredCanonicalForm()

	/**
	 * An integer and a float with the same value seal differently, so a type
	 * change of a sealed field is detected.
	 *
	 * @return void
	 */
	public function testAnIntegerAndAFloatSealDifferently(): void {
		$sealer = new RecordSeal();
		$seal   = $sealer->seal(definition: $this->definition(), record: ['url' => 'u', 'amount' => 1.0, 'title' => 't']);

		$this->assertTrue(condition: $sealer->verify(record: ['url' => 'u', 'amount' => 1.0, 'title' => 't'], seal: $seal));
		$this->assertFalse(condition: $sealer->verify(record: ['url' => 'u', 'amount' => 1, 'title' => 't'], seal: $seal));
	}//end testAnIntegerAndAFloatSealDifferently()

	/**
	 * The seal's own field list decides what is verified, in any order, and
	 * entries in it that are not strings are ignored.
	 *
	 * @return void
	 */
	public function testVerifyReadsTheSealsOwnFieldList(): void {
		$sealer = new RecordSeal();
		$record = ['url' => 'u', 'amount' => 2, 'title' => 't'];
		$seal   = $sealer->seal(definition: $this->definition(), record: $record);

		$reordered = (['fields' => ['title', 'url', 'amount']] + $seal);
		$this->assertTrue(condition: $sealer->verify(record: $record, seal: $reordered));

		$withNoise = (['fields' => ['url', 7, 'amount', null, 'title', ['x']]] + $seal);
		$this->assertTrue(condition: $sealer->verify(record: $record, seal: $withNoise));

		$onlyNoise = (['fields' => [7, null]] + $seal);
		$this->assertFalse(condition: $sealer->verify(record: $record, seal: $onlyNoise));

		$fewer = (['fields' => ['url', 'amount']] + $seal);
		$this->assertFalse(condition: $sealer->verify(record: $record, seal: $fewer));
	}//end testVerifyReadsTheSealsOwnFieldList()

	/**
	 * A hash that is not a string never verifies.
	 *
	 * @return void
	 */
	public function testANonStringHashNeverVerifies(): void {
		$sealer = new RecordSeal();
		$record = ['url' => 'u', 'amount' => 2, 'title' => 't'];
		$seal   = $sealer->seal(definition: $this->definition(), record: $record);

		$this->assertFalse(condition: $sealer->verify(record: $record, seal: (['hash' => null] + $seal)));
		$this->assertFalse(condition: $sealer->verify(record: $record, seal: (['hash' => [$seal['hash']]] + $seal)));
	}//end testANonStringHashNeverVerifies()
}//end class
