<?php

/**
 * RecordSeal unit tests.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/frozen-record/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\FrozenRecord;

use DateTimeImmutable;
use OCA\Dossiq\Service\FrozenRecord\RecordSeal;
use PHPUnit\Framework\TestCase;

/**
 * The seal on the neutral fixture record kind.
 *
 * @covers \OCA\Dossiq\Service\FrozenRecord\RecordSeal
 * @uses   \OCA\Dossiq\Service\FrozenRecord\FreezeDefinition
 */
class RecordSealTest extends TestCase {

	/**
	 * A seal verifies the record it was made from.
	 *
	 * @return void
	 */
	public function testSealVerifies(): void {
		$sealer = new RecordSeal();
		$record = FrozenRecordFixture::record(status: 'issued');
		$seal = $sealer->seal(definition: FrozenRecordFixture::definition(), record: $record, moment: new DateTimeImmutable('2026-10-10T12:00:00+00:00'));

		self::assertSame('sha256', $seal['algorithm']);
		self::assertSame(['body', 'amount', 'reference'], $seal['fields']);
		self::assertSame('2026-10-10T12:00:00+00:00', $seal['sealedAt']);
		self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $seal['hash']);
		self::assertTrue($sealer->verify(record: $record, seal: $seal));
	}//end testSealVerifies()

	/**
	 * Object key order does not change the hash; list order does.
	 *
	 * @return void
	 */
	public function testCanonicalForm(): void {
		$sealer = new RecordSeal();
		$definition = FrozenRecordFixture::definition(override: ['sealFields' => ['body']]);

		$one = $sealer->seal(definition: $definition, record: ['body' => ['a' => 1, 'b' => [1, 2]]]);
		$two = $sealer->seal(definition: $definition, record: ['body' => ['b' => [1, 2], 'a' => 1]]);
		$three = $sealer->seal(definition: $definition, record: ['body' => ['a' => 1, 'b' => [2, 1]]]);

		self::assertSame($one['hash'], $two['hash']);
		self::assertNotSame($one['hash'], $three['hash']);
	}//end testCanonicalForm()

	/**
	 * A changed sealed field breaks the seal; an unrelated new field does not.
	 *
	 * @return void
	 */
	public function testTamperIsDetected(): void {
		$sealer = new RecordSeal();
		$record = FrozenRecordFixture::record(status: 'issued');
		$seal = $sealer->seal(definition: FrozenRecordFixture::definition(), record: $record);

		self::assertFalse($sealer->verify(record: array_merge($record, ['amount' => 11]), seal: $seal));
		self::assertFalse($sealer->verify(record: array_diff_key($record, ['body' => true]), seal: $seal));
		self::assertTrue($sealer->verify(record: array_merge($record, ['added' => 'later']), seal: $seal));
	}//end testTamperIsDetected()

	/**
	 * A seal with an unknown algorithm or no fields never verifies.
	 *
	 * @return void
	 */
	public function testMalformedSealDoesNotVerify(): void {
		$sealer = new RecordSeal();
		$record = FrozenRecordFixture::record(status: 'issued');
		$seal = $sealer->seal(definition: FrozenRecordFixture::definition(), record: $record);

		self::assertFalse($sealer->verify(record: $record, seal: array_merge($seal, ['algorithm' => 'md5'])));
		self::assertFalse($sealer->verify(record: $record, seal: array_merge($seal, ['fields' => []])));
		self::assertFalse($sealer->verify(record: $record, seal: []));
	}//end testMalformedSealDoesNotVerify()
}//end class
