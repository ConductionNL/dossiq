<?php

/**
 * Unit tests for ZgwPatchMerger.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\Zgw\ZgwPatchMerger;
use PHPUnit\Framework\TestCase;

/**
 * Tests for laying a ZGW PATCH over the stored object.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZgwPatchMerger
 */
class ZgwPatchMergerTest extends TestCase {

	/**
	 * A zaaktype-like mapping: a plain field, an array field cast back, a JSON-string field,
	 * and a template that reads two ZGW fields.
	 *
	 * @var array
	 */
	private const MAPPING = [
		'reverseMapping' => [
			'title'              => '{{ omschrijving }}',
			'productsOrServices' => '{{ productenOfDiensten | json_encode }}',
			'relatedCaseTypes'   => '{{ gerelateerdeZaaktypen | json_encode }}',
			'period'             => '{{ beginGeldigheid }} {{ eindeGeldigheid }}',
		],
		'reverseCast'    => ['productsOrServices' => 'jsonToArray'],
	];

	/**
	 * Only the fields the body touched are taken over; the rest of the stored object stays.
	 *
	 * @return void
	 */
	public function testOnlyTouchedFieldsAreTakenOver(): void {
		$merged = (new ZgwPatchMerger())->merge(
			existingData: ['title' => 'Oud', 'description' => 'blijft', 'period' => 'x'],
			body: ['omschrijving' => 'Nieuw', 'beginGeldigheid' => '2026-01-01'],
			englishData: ['title' => 'Nieuw', 'description' => 'overschreven?', 'period' => 'y'],
			mappingConfig: self::MAPPING
		);

		$this->assertSame('Nieuw', $merged['title']);
		$this->assertSame('blijft', $merged['description']);
		$this->assertSame('x', $merged['period'], 'a template reading two fields is never taken over');

	}//end testOnlyTouchedFieldsAreTakenOver()

	/**
	 * A touched field the mapping did not produce is not taken over.
	 *
	 * @return void
	 */
	public function testTouchedFieldMissingFromTheMappedDataKeepsTheStoredValue(): void {
		$merged = (new ZgwPatchMerger())->merge(
			existingData: ['title' => 'Oud'],
			body: ['omschrijving' => 'Nieuw'],
			englishData: [],
			mappingConfig: self::MAPPING
		);

		$this->assertSame('Oud', $merged['title']);

	}//end testTouchedFieldMissingFromTheMappedDataKeepsTheStoredValue()

	/**
	 * Identity fields go, and an integer identifier becomes a string.
	 *
	 * @return void
	 */
	public function testIdentityFieldsAreDroppedAndTheIdentifierIsAString(): void {
		$merged = (new ZgwPatchMerger())->merge(
			existingData: ['@self' => ['id' => 'x'], 'id' => 'x', 'organisation' => 'o', 'identifier' => 123],
			body: [],
			englishData: [],
			mappingConfig: self::MAPPING
		);

		$this->assertSame(['identifier' => '123'], $merged);

	}//end testIdentityFieldsAreDroppedAndTheIdentifierIsAString()

	/**
	 * Stored arrays come back as arrays, except a field the schema stores as a JSON string.
	 *
	 * @return void
	 */
	public function testStoredArraysComeBackExceptJsonStringFields(): void {
		$merged = (new ZgwPatchMerger())->merge(
			existingData: [
				'productsOrServices' => ['https://p/1'],
				'relatedCaseTypes'   => [['caseType' => 'a']],
				'tags'               => ['x', 'y'],
			],
			body: ['productenOfDiensten' => ['https://p/2']],
			englishData: ['productsOrServices' => json_encode(['https://p/2'])],
			mappingConfig: self::MAPPING
		);

		$this->assertSame(['https://p/2'], $merged['productsOrServices'], 'an array property is written as an array');
		$this->assertSame(json_encode([['caseType' => 'a']]), $merged['relatedCaseTypes'], 'a JSON-string property stays a string');
		$this->assertSame(['x', 'y'], $merged['tags']);

	}//end testStoredArraysComeBackExceptJsonStringFields()

	/**
	 * A stored array patched with a value that is not JSON keeps the patched value.
	 *
	 * @return void
	 */
	public function testPatchedNonJsonValueIsKept(): void {
		$merged = (new ZgwPatchMerger())->merge(
			existingData: ['title' => ['was', 'an', 'array']],
			body: ['omschrijving' => 'Nieuw'],
			englishData: ['title' => 'Nieuw'],
			mappingConfig: self::MAPPING
		);

		$this->assertSame('Nieuw', $merged['title']);

	}//end testPatchedNonJsonValueIsKept()
}//end class
