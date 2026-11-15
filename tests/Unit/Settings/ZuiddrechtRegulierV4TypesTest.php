<?php

/**
 * Example data for DqZaaktypeStatussen and DqZaaktypeRegels: Omgevingsvergunning
 * regulier version 4 has its document types, decision types, fields and rules.
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

use PHPUnit\Framework\TestCase;

/**
 * What version 4 of Omgevingsvergunning regulier carries.
 */
class ZuiddrechtRegulierV4TypesTest extends TestCase {

	/**
	 * The types and fields belong to version 4, and its rules name only its own fields and document types.
	 *
	 * @return void
	 */
	public function testVersionFourCarriesItsTypesAndRules(): void {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$v4  = '{{object:zaaktype-omgevingsvergunning-regulier-v4.id}}';

		$names = [];
		foreach ($set['objects'] as $object) {
			if (($object['data']['caseType'] ?? null) === $v4) {
				$names[$object['schema']][] = ($object['data']['name'] ?? $object['data']['title']);
			}
		}

		$this->assertSame(expected: ['Aanvraagformulier', 'Bouwtekening', 'Welstandsadvies', 'Besluit'], actual: $names['documentType']);
		$this->assertSame(expected: ['Omgevingsvergunning verleend', 'Weigering omgevingsvergunning'], actual: $names['decisionType']);
		$this->assertSame(expected: ['bouwkosten', 'soortBouwwerk', 'welstandsgebied'], actual: $names['propertyDefinition']);

		$change = array_column($set['changes'], null, 'key')['zaaktype-omgevingsvergunning-regulier-v4-besluiten-en-regels'];
		$this->assertSame(expected: 'zaaktype-omgevingsvergunning-regulier-v4', actual: $change['object']);
		$this->assertCount(expectedCount: 2, haystack: $change['set']['decisionTypes']);
		foreach ($change['set']['rules'] as $rule) {
			foreach ($rule['conditions'] as $condition) {
				$this->assertContains(needle: $condition['field'], haystack: $names['propertyDefinition'], message: $rule['id']);
			}
		}
	}//end testVersionFourCarriesItsTypesAndRules()
}//end class
