<?php

/**
 * A case type lists the case fields its request card shows (`requestFields`),
 * and the Zuiddrecht Woo type lists the request's scope, period and kinds of
 * document.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-management/spec.md#requirement-a-case-type-lists-the-fields-its-request-card-shows
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The request fields setting, the card that reads it and the Woo type's list.
 */
class CaseTypeRequestFieldsTest extends TestCase {

	/**
	 * A JSON file under the app root.
	 *
	 * @param string $path The path from the app root.
	 *
	 * @return array<string, mixed>
	 */
	private static function json(string $path): array {
		return json_decode((string)file_get_contents(__DIR__.'/../../../'.$path), true);
	}//end json()

	/**
	 * The case type declares requestFields, a list of entries, so OpenRegister stores it.
	 *
	 * @return void
	 */
	public function testTheCaseTypeDeclaresRequestFields(): void {
		$schema = self::json(path: 'lib/Settings/register.d/99-case-type-request-fields.json')['components']['schemas']['caseType'];

		$this->assertSame(expected: 'array', actual: $schema['properties']['requestFields']['type']);
		$this->assertSame(expected: 'object', actual: $schema['properties']['requestFields']['items']['type']);
		$this->assertTrue(condition: version_compare($schema['version'], '1.42.0', '>'), message: 'the version moves so an instance re-imports');
	}//end testTheCaseTypeDeclaresRequestFields()

	/**
	 * The request card reads the case type's list and keeps the description as its fallback.
	 *
	 * @return void
	 */
	public function testTheRequestCardReadsTheCaseTypesList(): void {
		$layout = (string)file_get_contents(__DIR__.'/../../../src/menu-layout.simple.json');
		$card   = null;
		$find = static function ($node) use (&$find, &$card): void {
			if (is_array($node) === false) {
				return;
			}

			if (($node['id'] ?? null) === 'case-request') {
				$card = $node;
				return;
			}

			foreach ($node as $child) {
				$find($child);
			}
		};
		$find(json_decode($layout, true));

		$this->assertNotNull(actual: $card);
		$this->assertSame(expected: '@context.caseType.requestFields', actual: $card['content']['fieldsFrom']);
		$this->assertSame(expected: 'description', actual: $card['content']['fields'][0]['path']);
	}//end testTheRequestCardReadsTheCaseTypesList()

	/**
	 * The Woo type lists the request's scope, its period in years and the kinds of document.
	 *
	 * @return void
	 */
	public function testTheWooTypeListsItsRequestFields(): void {
		$changes = array_column(self::json(path: 'lib/Settings/example-sets/zuiddrecht.json')['changes'], null, 'key');
		$change  = $changes['woo-verzoek-request-fields'];

		$this->assertSame(expected: ['identifier' => 'woo-verzoek', 'version' => 2], actual: $change['where']);
		$paths = array_map(static fn (array $entry): string => (string)($entry['path'] ?? $entry['template']), $change['set']['requestFields']);
		$this->assertSame(
			expected: [
				'wooRequest.omschrijving',
				'Periode {wooRequest.periodeVan:year} tot en met {wooRequest.periodeTot:year}',
				'wooRequest.documentSoorten',
			],
			actual: $paths
		);

		$request = self::json(path: 'lib/Settings/register.d/81-woo-verzoek.json')['components']['schemas']['case']['properties']['wooRequest'];
		$kinds   = $request['properties']['documentSoorten']['items']['enum'];
		$labels  = array_keys($change['set']['requestFields'][2]['map']);
		$this->assertSame(expected: [], actual: array_diff($kinds, $labels), message: 'every kind has a label');
	}//end testTheWooTypeListsItsRequestFields()
}//end class
