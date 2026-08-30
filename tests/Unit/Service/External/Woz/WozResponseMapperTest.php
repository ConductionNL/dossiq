<?php

/**
 * WozResponseMapper unit tests (brk-woz-register-adapters).
 *
 * Normalization matrix: full record (with valuation history), partial
 * record (missing numeric / valuation fields), a single-string
 * gebruiksdoel, and multi-result mapping. `WozResponseMapper` is pure (no
 * I/O) so these tests exercise it directly with hand-built fragments, no
 * HTTP mocking required.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\External\Woz
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/brk-woz-register-adapters/proposal.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\External\Woz;

use OCA\Dossiq\Service\External\Woz\WozResponseMapper;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\External\Woz\WozResponseMapper
 */
class WozResponseMapperTest extends TestCase {
	/**
	 * A full record maps every field, selecting the MOST RECENT valuation
	 * from `vastgesteldeWaarden[]` regardless of input order.
	 *
	 * @return void
	 */
	public function testFullRecordSelectsMostRecentValuation(): void {
		$mapper = new WozResponseMapper();
		$mapped = $mapper->map(
			[
				'wozobjectnummer' => '05180000001234',
				'nummeraanduidingIdentificatie' => '0518010000123456',
				'grondoppervlakte' => 250,
				'gebruiksdoelen' => ['woonfunctie'],
				'vastgesteldeWaarden' => [
					['waardepeildatum' => '2024-01-01', 'vastgesteldeWaarde' => 362000],
					['waardepeildatum' => '2025-01-01', 'vastgesteldeWaarde' => 385000],
				],
			]
		);

		$this->assertSame('05180000001234', $mapped['wozobjectnummer']);
		$this->assertSame('0518010000123456', $mapped['addressDesignationId']);
		$this->assertSame(250, $mapped['grondoppervlakte']);
		$this->assertSame(['woonfunctie'], $mapped['gebruiksdoel']);
		$this->assertSame(385000, $mapped['value'], 'must select the 2025 (most recent) value, not the first array entry');
		$this->assertSame('2025-01-01', $mapped['waardepeildatum']);
	}//end testFullRecordSelectsMostRecentValuation()

	/**
	 * A partial record maps missing numeric/valuation fields to null,
	 * never `0`.
	 *
	 * @return void
	 */
	public function testPartialRecordMapsMissingFieldsToNull(): void {
		$mapper = new WozResponseMapper();
		$mapped = $mapper->map(['wozobjectnummer' => '05180000009999']);

		$this->assertNull($mapped['value']);
		$this->assertNull($mapped['waardepeildatum']);
		$this->assertNull($mapped['grondoppervlakte']);
		$this->assertNull($mapped['addressDesignationId']);
		$this->assertSame([], $mapped['gebruiksdoel'], 'missing gebruiksdoel must map to an empty array, not null');
	}//end testPartialRecordMapsMissingFieldsToNull()

	/**
	 * An empty fragment maps to a fully-null/empty DTO without throwing.
	 *
	 * @return void
	 */
	public function testEmptyRecordMapsWithoutError(): void {
		$mapper = new WozResponseMapper();
		$mapped = $mapper->map([]);

		$this->assertNull($mapped['wozobjectnummer']);
		$this->assertNull($mapped['value']);
		$this->assertNull($mapped['waardepeildatum']);
		$this->assertSame([], $mapped['gebruiksdoel']);
	}//end testEmptyRecordMapsWithoutError()

	/**
	 * A single-string gebruiksdoel is wrapped into a one-element array.
	 *
	 * @return void
	 */
	public function testSingleStringGebruiksdoelBecomesArray(): void {
		$mapper = new WozResponseMapper();
		$mapped = $mapper->map(['gebruiksdoel' => 'kantoorfunctie']);

		$this->assertSame(['kantoorfunctie'], $mapped['gebruiksdoel']);
	}//end testSingleStringGebruiksdoelBecomesArray()

	/**
	 * A flat `waarde`/`waardepeildatum` shape (no `vastgesteldeWaarden`
	 * history array) is still mapped — some Kadaster responses carry the
	 * current value flat rather than as a one-element history.
	 *
	 * @return void
	 */
	public function testFlatValuationShapeIsMapped(): void {
		$mapper = new WozResponseMapper();
		$mapped = $mapper->map(['value' => 250000, 'waardepeildatum' => '2025-01-01']);

		$this->assertSame(250000, $mapped['value']);
		$this->assertSame('2025-01-01', $mapped['waardepeildatum']);
	}//end testFlatValuationShapeIsMapped()

	/**
	 * `mapMany` normalizes a list of fragments, preserving order, and
	 * skips non-array entries defensively.
	 *
	 * @return void
	 */
	public function testMapManyNormalizesListInOrder(): void {
		$mapper = new WozResponseMapper();
		$mapped = $mapper->mapMany(
			[
				['wozobjectnummer' => '1111'],
				['wozobjectnummer' => '2222'],
				'not-an-array',
			]
		);

		$this->assertCount(2, $mapped);
		$this->assertSame('1111', $mapped[0]['wozobjectnummer']);
		$this->assertSame('2222', $mapped[1]['wozobjectnummer']);
	}//end testMapManyNormalizesListInOrder()

	/**
	 * A numeric-string grondoppervlakte/waarde (as query-parsed JSON may
	 * carry) is coerced to an int.
	 *
	 * @return void
	 */
	public function testNumericStringFieldsAreCoercedToInt(): void {
		$mapper = new WozResponseMapper();
		$mapped = $mapper->map(['grondoppervlakte' => '99', 'value' => '250000']);

		$this->assertSame(99, $mapped['grondoppervlakte']);
		$this->assertSame(250000, $mapped['value']);
	}//end testNumericStringFieldsAreCoercedToInt()

	/**
	 * `adresseerbaarObjectIdentificatie` is accepted as a fallback source
	 * for `nummeraanduidingId` when `nummeraanduidingIdentificatie` is
	 * absent.
	 *
	 * @return void
	 */
	public function testAdresseerbaarObjectIdentificatieFallsBackToNummeraanduidingId(): void {
		$mapper = new WozResponseMapper();
		$mapped = $mapper->map(['adresseerbaarObjectIdentificatie' => '0518010000999999']);

		$this->assertSame('0518010000999999', $mapped['addressDesignationId']);
	}//end testAdresseerbaarObjectIdentificatieFallsBackToNummeraanduidingId()
}//end class
