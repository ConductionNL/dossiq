<?php

/**
 * BrkResponseMapper unit tests (brk-woz-register-adapters).
 *
 * Normalization matrix: full record, partial record (missing numeric /
 * geo / zakelijkGerechtigden fields), a single-string
 * soortCultuurBebouwd, and multi-result mapping. `BrkResponseMapper` is
 * pure (no I/O) so these tests exercise it directly with hand-built
 * fragments, no HTTP mocking required.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\External\Brk
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\External\Brk;

use OCA\Dossiq\Service\External\Brk\BrkResponseMapper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\External\Brk\BrkResponseMapper
 */
class BrkResponseMapperTest extends TestCase {
	/**
	 * A full record maps every field, including zakelijkGerechtigden
	 * references and a WGS84 centroid geo point.
	 *
	 * @return void
	 */
	public function testFullRecordMapsEveryField(): void {
		$mapper = new BrkResponseMapper();
		$mapped = $mapper->map(
			[
				'kadastraleAanduiding' => [
					'kadastraleGemeentecode' => ['value' => 'VBSTD'],
					'kadastraleGemeente' => ['value' => 'Voorbeeldstad'],
					'sectie' => 'A',
					'perceelnummer' => 1234,
				],
				'kadastraleAanduidingVolledig' => 'VBSTD A 1234',
				'kadastraleGrootte' => ['value' => 350],
				'soortCultuurBebouwd' => ['wonen'],
				'zakelijkGerechtigdheid' => [
					['identificatie' => 'ZG0001', 'aardZakelijkRecht' => ['value' => 'Eigendom (recht van)']],
				],
				'centroideLL' => ['type' => 'Point', 'coordinates' => [4.4699, 51.9244]],
			]
		);

		$this->assertSame('Voorbeeldstad', $mapped['kadastraleGemeente']);
		$this->assertSame('VBSTD', $mapped['kadastraleGemeenteCode']);
		$this->assertSame('A', $mapped['sectie']);
		$this->assertSame(1234, $mapped['perceelnummer']);
		$this->assertNull($mapped['appartementsrechtVolgnummer']);
		$this->assertSame('VBSTD A 1234', $mapped['kadastraleAanduiding']);
		$this->assertSame(350, $mapped['oppervlakte']);
		$this->assertSame(['wonen'], $mapped['soortCultuurBebouwd']);
		$this->assertCount(1, $mapped['zakelijkGerechtigden']);
		$this->assertSame('ZG0001', $mapped['zakelijkGerechtigden'][0]['identificatie']);
		$this->assertSame('Eigendom (recht van)', $mapped['zakelijkGerechtigden'][0]['aardZakelijkRecht']);
		$this->assertSame(['lng' => 4.4699, 'lat' => 51.9244], $mapped['geo']);
	}//end testFullRecordMapsEveryField()

	/**
	 * A partial record maps missing numeric fields to null, never `0`
	 * — and geo/zakelijkGerechtigden are null/empty when absent.
	 *
	 * @return void
	 */
	public function testPartialRecordMapsMissingFieldsToNull(): void {
		$mapper = new BrkResponseMapper();
		$mapped = $mapper->map(
			[
				'kadastraleAanduiding' => ['sectie' => 'B', 'perceelnummer' => 42],
			]
		);

		$this->assertNull($mapped['oppervlakte']);
		$this->assertNull($mapped['geo']);
		$this->assertNull($mapped['kadastraleGemeente']);
		$this->assertNull($mapped['kadastraleAanduiding']);
		$this->assertSame([], $mapped['soortCultuurBebouwd'], 'missing soortCultuurBebouwd must map to an empty array, not null');
		$this->assertSame([], $mapped['zakelijkGerechtigden'], 'missing zakelijkGerechtigden must map to an empty array, not null');
	}//end testPartialRecordMapsMissingFieldsToNull()

	/**
	 * An empty fragment maps to a fully-null/empty DTO without throwing.
	 *
	 * @return void
	 */
	public function testEmptyRecordMapsWithoutError(): void {
		$mapper = new BrkResponseMapper();
		$mapped = $mapper->map([]);

		$this->assertNull($mapped['kadastraleGemeente']);
		$this->assertNull($mapped['sectie']);
		$this->assertNull($mapped['perceelnummer']);
		$this->assertSame([], $mapped['soortCultuurBebouwd']);
		$this->assertSame([], $mapped['zakelijkGerechtigden']);
		$this->assertNull($mapped['geo']);
	}//end testEmptyRecordMapsWithoutError()

	/**
	 * A single-string soortCultuurBebouwd is wrapped into a one-element
	 * array.
	 *
	 * @return void
	 */
	public function testSingleStringSoortCultuurBebouwdBecomesArray(): void {
		$mapper = new BrkResponseMapper();
		$mapped = $mapper->map(['soortCultuurBebouwd' => 'bedrijvigheid']);

		$this->assertSame(['bedrijvigheid'], $mapped['soortCultuurBebouwd']);
	}//end testSingleStringSoortCultuurBebouwdBecomesArray()

	/**
	 * A single (non-list) zakelijkGerechtigdheid entry is wrapped into a
	 * one-element list, never dropped.
	 *
	 * @return void
	 */
	public function testSingleZakelijkGerechtigdheidEntryIsWrapped(): void {
		$mapper = new BrkResponseMapper();
		$mapped = $mapper->map(
			['zakelijkGerechtigdheid' => ['identificatie' => 'ZG9999', 'aardZakelijkRecht' => 'Erfpacht']]
		);

		$this->assertCount(1, $mapped['zakelijkGerechtigden']);
		$this->assertSame('ZG9999', $mapped['zakelijkGerechtigden'][0]['identificatie']);
		$this->assertSame('Erfpacht', $mapped['zakelijkGerechtigden'][0]['aardZakelijkRecht']);
	}//end testSingleZakelijkGerechtigdheidEntryIsWrapped()

	/**
	 * Only reference fields (identificatie + aardZakelijkRecht) are
	 * mapped for zakelijkGerechtigden — no inline personal data, even
	 * when the raw fragment carries a `naam` field (privacy scoping,
	 * design.md Decision 1).
	 *
	 * @return void
	 */
	public function testZakelijkGerechtigdenNeverLeaksPersonalDataFields(): void {
		$mapper = new BrkResponseMapper();
		$mapped = $mapper->map(
			[
				'zakelijkGerechtigdheid' => [
					['identificatie' => 'ZG0002', 'aardZakelijkRecht' => 'Eigendom', 'name' => 'J. Jansen', 'bsn' => '123456782'],
				],
			]
		);

		$this->assertSame(['identificatie' => 'ZG0002', 'aardZakelijkRecht' => 'Eigendom'], $mapped['zakelijkGerechtigden'][0]);
		$this->assertArrayNotHasKey('name', $mapped['zakelijkGerechtigden'][0]);
		$this->assertArrayNotHasKey('bsn', $mapped['zakelijkGerechtigden'][0]);
	}//end testZakelijkGerechtigdenNeverLeaksPersonalDataFields()

	/**
	 * `mapMany` normalizes a list of fragments, preserving order, and
	 * skips non-array entries defensively.
	 *
	 * @return void
	 */
	public function testMapManyNormalizesListInOrder(): void {
		$mapper = new BrkResponseMapper();
		$mapped = $mapper->mapMany(
			[
				['kadastraleAanduiding' => ['sectie' => 'A', 'perceelnummer' => 1]],
				['kadastraleAanduiding' => ['sectie' => 'B', 'perceelnummer' => 2]],
				'not-an-array',
			]
		);

		$this->assertCount(2, $mapped);
		$this->assertSame('A', $mapped[0]['sectie']);
		$this->assertSame('B', $mapped[1]['sectie']);
	}//end testMapManyNormalizesListInOrder()

	/**
	 * A numeric-string perceelnummer/kadastraleGrootte (as query-parsed
	 * JSON may carry) is coerced to an int.
	 *
	 * @return void
	 */
	public function testNumericStringFieldsAreCoercedToInt(): void {
		$mapper = new BrkResponseMapper();
		$mapped = $mapper->map(
			[
				'kadastraleAanduiding' => ['perceelnummer' => '99'],
				'kadastraleGrootte' => '500',
			]
		);

		$this->assertSame(99, $mapped['perceelnummer']);
		$this->assertSame(500, $mapped['oppervlakte']);
	}//end testNumericStringFieldsAreCoercedToInt()
}//end class
