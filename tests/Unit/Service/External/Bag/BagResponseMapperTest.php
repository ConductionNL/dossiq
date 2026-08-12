<?php

/**
 * BagResponseMapper unit tests (bag-register-adapter).
 *
 * Normalization matrix: full record, partial record (missing numeric /
 * geo fields), a single-string gebruiksdoel, and multi-result mapping.
 * `BagResponseMapper` is pure (no I/O) so these tests exercise it directly
 * with hand-built fragments, no HTTP mocking required.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\External\Bag
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/bag-register-adapter/proposal.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\External\Bag;

use OCA\Procest\Service\External\Bag\BagResponseMapper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Service\External\Bag\BagResponseMapper
 */
class BagResponseMapperTest extends TestCase {
	/**
	 * A full record maps every field, including a WGS84 geo point.
	 *
	 * @return void
	 */
	public function testFullRecordMapsEveryField(): void {
		$mapper = new BagResponseMapper();
		$mapped = $mapper->map(
			[
				'openbareRuimteNaam' => 'Voorstraat',
				'huisnummer' => 10,
				'huisletter' => 'A',
				'huisnummertoevoeging' => 'II',
				'postcode' => '1234AB',
				'woonplaatsNaam' => 'Voorbeeldstad',
				'gebruiksdoelen' => ['woonfunctie'],
				'oorspronkelijkBouwjaar' => 1998,
				'oppervlakte' => 120,
				'geometrie' => ['punt' => ['type' => 'Point', 'coordinates' => [4.4699, 51.9244]]],
			]
		);

		$this->assertSame('Voorstraat', $mapped['street']);
		$this->assertSame(10, $mapped['houseNumber']);
		$this->assertSame('A', $mapped['houseLetter']);
		$this->assertSame('II', $mapped['houseNumberAddition']);
		$this->assertSame('1234AB', $mapped['postcode']);
		$this->assertSame('Voorbeeldstad', $mapped['city']);
		$this->assertSame(['woonfunctie'], $mapped['gebruiksdoel']);
		$this->assertSame(1998, $mapped['oorspronkelijkBouwjaar']);
		$this->assertSame(120, $mapped['oppervlakte']);
		$this->assertSame(['lng' => 4.4699, 'lat' => 51.9244], $mapped['geo']);
	}//end testFullRecordMapsEveryField()

	/**
	 * A partial record maps missing numeric fields to null, never `0`
	 * (distinguishable from a real zero) — and geo is null when absent.
	 *
	 * @return void
	 */
	public function testPartialRecordMapsMissingFieldsToNull(): void {
		$mapper = new BagResponseMapper();
		$mapped = $mapper->map(
			[
				'openbareRuimteNaam' => 'Kade',
				'huisnummer' => 1,
				'postcode' => '5678CD',
			]
		);

		$this->assertNull($mapped['oorspronkelijkBouwjaar']);
		$this->assertNull($mapped['oppervlakte']);
		$this->assertNull($mapped['geo']);
		$this->assertNull($mapped['houseLetter']);
		$this->assertNull($mapped['houseNumberAddition']);
		$this->assertNull($mapped['city']);
		$this->assertSame([], $mapped['gebruiksdoel'], 'missing gebruiksdoel must map to an empty array, not null');
	}//end testPartialRecordMapsMissingFieldsToNull()

	/**
	 * An empty fragment maps to a fully-null/empty DTO without throwing.
	 *
	 * @return void
	 */
	public function testEmptyRecordMapsWithoutError(): void {
		$mapper = new BagResponseMapper();
		$mapped = $mapper->map([]);

		$this->assertNull($mapped['street']);
		$this->assertNull($mapped['houseNumber']);
		$this->assertNull($mapped['postcode']);
		$this->assertSame([], $mapped['gebruiksdoel']);
		$this->assertNull($mapped['geo']);
	}//end testEmptyRecordMapsWithoutError()

	/**
	 * A single-string gebruiksdoel (as some pand fragments carry) is
	 * wrapped into a one-element array.
	 *
	 * @return void
	 */
	public function testSingleStringGebruiksdoelBecomesArray(): void {
		$mapper = new BagResponseMapper();
		$mapped = $mapper->map(['gebruiksdoel' => 'kantoorfunctie']);

		$this->assertSame(['kantoorfunctie'], $mapped['gebruiksdoel']);
	}//end testSingleStringGebruiksdoelBecomesArray()

	/**
	 * A `vlak` polygon geometry (typical for panden) yields a null geo
	 * point — only `punt` geometries are extracted.
	 *
	 * @return void
	 */
	public function testPolygonGeometryYieldsNullGeo(): void {
		$mapper = new BagResponseMapper();
		$mapped = $mapper->map(
			[
				'geometrie' => [
					'vlak' => [
						'type' => 'Polygon',
						'coordinates' => [[[4.47, 51.92], [4.48, 51.92], [4.48, 51.93], [4.47, 51.92]]],
					],
				],
			]
		);

		$this->assertNull($mapped['geo']);
	}//end testPolygonGeometryYieldsNullGeo()

	/**
	 * `mapMany` normalizes a list of fragments, preserving order, and
	 * skips non-array entries defensively.
	 *
	 * @return void
	 */
	public function testMapManyNormalizesListInOrder(): void {
		$mapper = new BagResponseMapper();
		$mapped = $mapper->mapMany(
			[
				['postcode' => '1111AA', 'huisnummer' => 1],
				['postcode' => '2222BB', 'huisnummer' => 2],
				'not-an-array',
			]
		);

		$this->assertCount(2, $mapped);
		$this->assertSame('1111AA', $mapped[0]['postcode']);
		$this->assertSame('2222BB', $mapped[1]['postcode']);
	}//end testMapManyNormalizesListInOrder()

	/**
	 * A numeric-string huisnummer (as query-parsed JSON may carry) is
	 * coerced to an int.
	 *
	 * @return void
	 */
	public function testNumericStringFieldsAreCoercedToInt(): void {
		$mapper = new BagResponseMapper();
		$mapped = $mapper->map(['huisnummer' => '42', 'oppervlakte' => '85']);

		$this->assertSame(42, $mapped['houseNumber']);
		$this->assertSame(85, $mapped['oppervlakte']);
	}//end testNumericStringFieldsAreCoercedToInt()
}//end class
