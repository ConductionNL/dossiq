<?php

/**
 * ReferencedUuid and CastArray: reading ids and arrays out of loosely-typed values.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\CastArray;
use OCA\Dossiq\Service\Support\ReferencedUuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A reference is a string or a row; anything else references nothing. Any value casts to an array.
 */
#[CoversClass(ReferencedUuid::class)]
#[CoversClass(CastArray::class)]
final class ReferencedUuidAndCastArrayTest extends TestCase {

	public function testADossierReferenceIsReadFromTheFirstStringKeyInOrder(): void {
		$this->assertSame('u-1', ReferencedUuid::inDossierReference(' u-1 '));
		$this->assertSame('id-1', ReferencedUuid::inDossierReference(['uuid' => 'u-1', 'id' => ' id-1 ']));
		$this->assertSame('u-1', ReferencedUuid::inDossierReference(['id' => 7, 'uuid' => 'u-1']));
		$this->assertSame('c-1', ReferencedUuid::inDossierReference(['case' => 'c-1', 'target' => 't-1']));
		$this->assertSame('t-1', ReferencedUuid::inDossierReference(['target' => 't-1']));
		$this->assertSame('', ReferencedUuid::inDossierReference(['name' => 'x']));
		$this->assertSame('', ReferencedUuid::inDossierReference(42));
		$this->assertSame('', ReferencedUuid::inDossierReference(null));
	}//end testADossierReferenceIsReadFromTheFirstStringKeyInOrder()

	public function testAPlainReferenceReadsOnlyTheId(): void {
		$this->assertSame('x-1', ReferencedUuid::inReference(' x-1 '));
		$this->assertSame('id-1', ReferencedUuid::inReference(['id' => ' id-1 ', 'uuid' => 'u-1']));
		$this->assertSame('', ReferencedUuid::inReference(['uuid' => 'u-1']));
		$this->assertSame('', ReferencedUuid::inReference(1.5));
	}//end testAPlainReferenceReadsOnlyTheId()

	public function testAnyValueCastsToAnArray(): void {
		$this->assertSame(['a' => 1], CastArray::of(['a' => 1]));
		$this->assertSame(['b' => 2], CastArray::of(new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['b' => 2];
			}
		}));
		$object = new \stdClass();
		$object->c = 3;
		$this->assertSame(['c' => 3], CastArray::of($object));
		$this->assertSame([], CastArray::of('text'));
		$this->assertSame([], CastArray::of(null));
	}//end testAnyValueCastsToAnArray()
}//end class
