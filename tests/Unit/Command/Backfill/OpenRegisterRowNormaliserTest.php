<?php

/**
 * Turning whatever OpenRegister answers into a uuid and a data array.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Command\Backfill
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Command\Backfill;

use JsonSerializable;
use OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Arrays, entities, serialisable objects and anything else, with the uuid
 * taken from the first place that holds one.
 *
 * @covers \OCA\Dossiq\Command\Backfill\OpenRegisterRowNormaliser
 */
final class OpenRegisterRowNormaliserTest extends TestCase {
	private OpenRegisterRowNormaliser $normaliser;

	protected function setUp(): void {
		$this->normaliser = new OpenRegisterRowNormaliser();
	}//end setUp()

	public function testAnArrayKeepsItsDataAndPrefersTheSelfUuid(): void {
		$row = ['@self' => ['uuid' => 's-uuid', 'id' => 's-id'], 'uuid' => 'r-uuid', 'id' => 'r-id', 'title' => 'Bezwaar'];

		$this->assertSame(['uuid' => 's-uuid', 'data' => $row], $this->normaliser->normalise(row: $row));
	}//end testAnArrayKeepsItsDataAndPrefersTheSelfUuid()

	/**
	 * @return array<string, array{0: array, 1: string}>
	 */
	public static function uuidSources(): array {
		return [
			'self id' => [['@self' => ['id' => 's-id'], 'uuid' => 'r-uuid'], 's-id'],
			'row uuid when self is empty' => [['@self' => ['uuid' => ''], 'uuid' => 'r-uuid', 'id' => 'r-id'], 'r-uuid'],
			'row id' => [['id' => 42], '42'],
			'self that is no array is ignored' => [['@self' => 'x', 'id' => 'r-id'], 'r-id'],
			'non-scalar values are skipped' => [['@self' => ['uuid' => ['nested']], 'uuid' => null, 'id' => 'r-id'], 'r-id'],
			'nothing' => [['title' => 'Zonder id'], ''],
		];
	}//end uuidSources()

	/**
	 * @dataProvider uuidSources
	 *
	 * @param array  $row      The row.
	 * @param string $expected The uuid it yields.
	 */
	public function testTheUuidComesFromTheFirstPlaceThatHoldsOne(array $row, string $expected): void {
		$this->assertSame($expected, $this->normaliser->normalise(row: $row)['uuid']);
	}//end testTheUuidComesFromTheFirstPlaceThatHoldsOne()

	public function testAnEntityGivesItsUuidAndObject(): void {
		$entity = new class {
			public function getUuid(): string {
				return 'e-uuid';
			}

			public function getId(): int {
				return 7;
			}

			public function getObject(): array {
				return ['title' => 'Bezwaar'];
			}

			public function jsonSerialize(): array {
				return ['id' => 'serialised'];
			}
		};

		$this->assertSame(['uuid' => 'e-uuid', 'data' => ['title' => 'Bezwaar']], $this->normaliser->normalise(row: $entity));
	}//end testAnEntityGivesItsUuidAndObject()

	public function testAnEntityWithoutUuidFallsBackToItsId(): void {
		$entity = new class {
			public function getUuid(): ?string {
				return null;
			}

			public function getId(): int {
				return 7;
			}
		};

		$this->assertSame(['uuid' => '7', 'data' => []], $this->normaliser->normalise(row: $entity));
	}//end testAnEntityWithoutUuidFallsBackToItsId()

	public function testASerialisableObjectGivesItsSerialisedUuidAndData(): void {
		$row = new class implements JsonSerializable {
			public function jsonSerialize(): array {
				return ['@self' => ['uuid' => 'j-uuid'], 'title' => 'Bezwaar'];
			}
		};

		$this->assertSame(['uuid' => 'j-uuid', 'data' => ['@self' => ['uuid' => 'j-uuid'], 'title' => 'Bezwaar']], $this->normaliser->normalise(row: $row));
	}//end testASerialisableObjectGivesItsSerialisedUuidAndData()

	public function testTheObjectDataWinsOverTheSerialisedDataWhenOnlyTheUuidIsMissing(): void {
		$row = new class implements JsonSerializable {
			public function getObject(): array {
				return ['title' => 'Uit het object'];
			}

			public function jsonSerialize(): array {
				return ['uuid' => 'j-uuid', 'title' => 'Geserialiseerd'];
			}
		};

		$this->assertSame(['uuid' => 'j-uuid', 'data' => ['title' => 'Uit het object']], $this->normaliser->normalise(row: $row));
	}//end testTheObjectDataWinsOverTheSerialisedDataWhenOnlyTheUuidIsMissing()

	public function testAnObjectWithNothingUsableIsEmpty(): void {
		$row = new class {
			public function getObject(): string {
				return 'not an array';
			}

			public function jsonSerialize(): string {
				return 'not an array';
			}
		};

		$this->assertSame(['uuid' => '', 'data' => []], $this->normaliser->normalise(row: $row));
	}//end testAnObjectWithNothingUsableIsEmpty()

	public function testAScalarIsEmpty(): void {
		$this->assertSame(['uuid' => '', 'data' => []], $this->normaliser->normalise(row: 'row-1'));
		$this->assertSame(['uuid' => '', 'data' => []], $this->normaliser->normalise(row: null));
	}//end testAScalarIsEmpty()
}//end class
