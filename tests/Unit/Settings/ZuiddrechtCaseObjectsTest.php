<?php

/**
 * Example data for DqCatalogus: the cases about a tree, a play item or a
 * market pitch link that object in OpenRegister's public-space register, so
 * the object type catalogue lists Boom, Speeltoestel and Standplaats with the
 * schema behind each.
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
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Catalogue\ObjectTypeSchemaResolver;
use PHPUnit\Framework\TestCase;

/**
 * The case-object links of the Zuiddrecht fragment.
 */
class ZuiddrechtCaseObjectsTest extends TestCase {

	/**
	 * The fragment's objects, in file order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function objects(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);

		return $set['objects'];
	}//end objects()

	/**
	 * Every link finds its object by identification in the schema its URL
	 * names, and the URL names a schema the catalogue can read.
	 *
	 * @return void
	 */
	public function testEveryLinkFindsItsObject(): void {
		$keys  = array_column($this->objects(), 'key');
		$links = array_filter($this->objects(), static fn (array $object): bool => $object['schema'] === 'caseObject');
		$this->assertCount(expectedCount: 9, haystack: $links);

		foreach ($links as $link) {
			$lookup = $link['lookups']['object'];
			$this->assertSame(expected: 'public-space', actual: $lookup['register'], message: $link['key']);
			$this->assertSame(expected: ['identification' => $link['data']['objectIdentification']], actual: $lookup['where'], message: $link['key']);
			$this->assertSame(
				expected: $lookup['schema'],
				actual: ObjectTypeSchemaResolver::schemaNamedIn(objectUrl: $link['data']['objectUrl']),
				message: $link['key']
			);
			$this->assertStringEndsWith(suffix: '/{{lookup:object}}', string: $link['data']['objectUrl'], message: $link['key']);

			$case = substr($link['data']['case'], strlen('{{object:'), -strlen('.id}}'));
			$this->assertLessThan(expected: array_search($link['key'], $keys, true), actual: array_search($case, $keys, true), message: $link['key']);
		}
	}//end testEveryLinkFindsItsObject()

	/**
	 * The cases per object type: trees on two permits and the neighbourhood
	 * party, the spring rider on its report and the party, a market pitch on
	 * an objection and the party.
	 *
	 * @return void
	 */
	public function testTheCasesPerObjectType(): void {
		$cases = [];
		foreach ($this->objects() as $object) {
			if ($object['schema'] === 'caseObject') {
				$cases[$object['data']['objectType']][$object['data']['case']] = true;
			}
		}

		$this->assertSame(
			expected: [
				'Boom'         => [
					'{{object:kapvergunning-parkstraat.id}}',
					'{{object:kapvergunning-essen-parkweg.id}}',
					'{{object:evenement-buurtfeest-2026.id}}',
				],
				'Speeltoestel' => ['{{object:evenement-buurtfeest-2026.id}}', '{{object:melding-speeltoestel-oosthavenplein.id}}'],
				'Standplaats'  => ['{{object:evenement-buurtfeest-2026.id}}', '{{object:besloten-bezwaar-standplaats-markt.id}}'],
			],
			actual: array_map(static fn (array $byCase): array => array_keys($byCase), $cases)
		);
	}//end testTheCasesPerObjectType()
}//end class
