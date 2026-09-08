<?php

/**
 * Conformance of the shipped document-zaakdossier register fragment.
 *
 * A property added to a register JSON is INERT until the app upgrades: the
 * import runs in the InitializeSettings repair step at `occ upgrade`, so no
 * local run and no CI leg can see the imported result. What CAN be asserted
 * here is the declaration itself, which is the half a typo lives in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The `keywords` and `direction` properties of `informatieobject`.
 *
 * @covers \OCA\Dossiq\Service\SettingsService
 */
class DocumentZaakdossierSchemaTest extends TestCase {

	/**
	 * The register fragment path.
	 */
	private const FRAGMENT = __DIR__ . '/../../../lib/Settings/register.d/70-document-zaakdossier.json';

	/**
	 * The decoded fragment.
	 *
	 * @var array<string, mixed>
	 */
	private array $fragment = [];

	/**
	 * Decode the shipped fragment.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$raw = file_get_contents(self::FRAGMENT);
		self::assertIsString($raw, 'The register fragment must be readable');

		$decoded = json_decode($raw, true);
		self::assertIsArray($decoded, 'The register fragment must be valid JSON');

		$this->fragment = $decoded;
	}//end setUp()

	/**
	 * The `informatieobject` schema declaration.
	 *
	 * @return array<string, mixed> The schema.
	 */
	private function informatieobject(): array {
		$schema = ($this->fragment['components']['schemas']['informatieobject'] ?? null);
		self::assertIsArray($schema, 'The fragment must declare the informatieobject schema');

		return $schema;
	}//end informatieobject()

	/**
	 * REQ-ZAK-012: keywords is an array of short strings, optional and facetable.
	 *
	 * @return void
	 */
	public function testKeywordsIsAnOptionalArrayOfStrings(): void {
		$schema = $this->informatieobject();
		$keywords = ($schema['properties']['keywords'] ?? null);

		self::assertIsArray($keywords, 'informatieobject must declare keywords');
		self::assertSame('array', $keywords['type']);
		self::assertSame('string', $keywords['items']['type']);
		self::assertSame(64, $keywords['items']['maxLength']);
		self::assertTrue($keywords['facetable'], 'keywords must be facetable to drive the filter');
		self::assertSame('tags', $keywords['x-widget']);
		self::assertNotContains(
			'keywords',
			($schema['required'] ?? []),
			'keywords is optional: back-filled documents carry none'
		);
	}//end testKeywordsIsAnOptionalArrayOfStrings()

	/**
	 * REQ-ZAK-013: direction is the three-value enum, defaulting to internal.
	 *
	 * @return void
	 */
	public function testDirectionIsAnEnumDefaultingToInternal(): void {
		$schema = $this->informatieobject();
		$direction = ($schema['properties']['direction'] ?? null);

		self::assertIsArray($direction, 'informatieobject must declare direction');
		self::assertSame('string', $direction['type']);
		self::assertSame(['incoming', 'outgoing', 'internal'], $direction['enum']);
		self::assertSame('internal', $direction['default']);
		self::assertTrue($direction['facetable']);
		self::assertNotContains(
			'direction',
			($schema['required'] ?? []),
			'direction is optional: the default carries a document that names none'
		);
	}//end testDirectionIsAnEnumDefaultingToInternal()

	/**
	 * The property name is English, and the Dutch spelling appears nowhere.
	 *
	 * A `trefwoorden` property would validate every bit as well as `keywords`
	 * and read identically on a Dutch screen, so nothing but this test stops
	 * the two names from both existing.
	 *
	 * @return void
	 */
	public function testTheKeywordPropertyIsEnglishEverywhereInTheFile(): void {
		$raw = (string)file_get_contents(self::FRAGMENT);

		self::assertStringNotContainsStringIgnoringCase(
			'trefwoorden',
			$raw,
			'The property is keywords (decisions D13); Trefwoorden is a label in nl.json'
		);
	}//end testTheKeywordPropertyIsEnglishEverywhereInTheFile()

	/**
	 * Two new properties are a schema change, so the version moves.
	 *
	 * @return void
	 */
	public function testTheSchemaVersionCarriesTheTwoNewProperties(): void {
		self::assertSame('1.1.0', $this->informatieobject()['version']);
	}//end testTheSchemaVersionCarriesTheTwoNewProperties()

	/**
	 * The join the Documents tab filters on is unchanged and still carries `case`.
	 *
	 * @return void
	 */
	public function testTheCaseJoinStillCarriesTheCaseReference(): void {
		$join = ($this->fragment['components']['schemas']['zaakinformatieobject'] ?? []);

		self::assertArrayHasKey('case', ($join['properties'] ?? []));
		self::assertTrue($join['properties']['case']['facetable']);
	}//end testTheCaseJoinStillCarriesTheCaseReference()
}//end class
