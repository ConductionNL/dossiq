<?php

/**
 * Example data for DqZaaktypeVelden: Omgevingsvergunning declares the six
 * fields its child Omgevingsvergunning regulier inherits, and the set's
 * omgevingsvergunning cases answer the required ones.
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
 * The fields Omgevingsvergunning passes on to its children.
 */
class ZuiddrechtOmgevingsvergunningInheritedFieldsTest extends TestCase {

	/**
	 * The Zuiddrecht fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function set(): array {
		return json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
	}//end set()

	/**
	 * The fields the set declares on the parent type, by name, in fragment order.
	 *
	 * @param array<string, mixed> $set The fragment.
	 *
	 * @return array<string, array<string, mixed>> The field objects, by field name.
	 */
	private function parentFields(array $set): array {
		$fields = [];
		foreach ($set['objects'] as $object) {
			if ($object['schema'] === 'propertyDefinition'
				&& ($object['data']['caseType'] ?? null) === '{{lookup:parent}}'
				&& ($object['lookups']['parent']['where'] ?? null) === ['identifier' => 'omgevingsvergunning']
			) {
				$fields[$object['data']['name']] = $object;
			}
		}

		return $fields;
	}//end parentFields()

	/**
	 * Six inherited fields; Locatie and Aanvrager are required, the rest optional.
	 *
	 * @return void
	 */
	public function testTheParentDeclaresSixFields(): void {
		$fields = $this->parentFields(set: $this->set());

		$this->assertSame(
			expected: ['locatie', 'aanvrager', 'datumIndieningDso', 'omschrijvingProject', 'dsoVerzoeknummer', 'kadastraalPerceel'],
			actual: array_keys($fields)
		);
		$this->assertSame(
			expected: ['Locatie', 'Aanvrager', 'Datum indiening DSO', 'Omschrijving van het project', 'DSO-verzoeknummer', 'Kadastraal perceel'],
			actual: array_values(array_map(static fn (array $field): string => $field['data']['definition'], $fields))
		);
		$this->assertSame(
			expected: [
				'locatie'             => true,
				'aanvrager'           => true,
				'datumIndieningDso'   => false,
				'omschrijvingProject' => false,
				'dsoVerzoeknummer'    => false,
				'kadastraalPerceel'   => false,
			],
			actual: array_map(static fn (array $field): bool => $field['data']['isRequired'], $fields)
		);
		$this->assertSame(expected: 'bag', actual: $fields['locatie']['data']['propertySource']);
		$this->assertSame(expected: 'date', actual: $fields['datumIndieningDso']['data']['propertyType']);
	}//end testTheParentDeclaresSixFields()

	/**
	 * Every omgevingsvergunning case in the set comes after the fields and answers Locatie;
	 * every one that names a requester answers Aanvrager.
	 *
	 * @return void
	 */
	public function testTheParentsCasesAnswerTheRequiredFields(): void {
		$set    = $this->set();
		$keys   = array_column($set['objects'], 'key');
		$fields = $this->parentFields(set: $set);
		$last   = max(array_map(static fn (array $field): int|string => array_search($field['key'], $keys, true), $fields));

		$cases = 0;
		foreach ($set['objects'] as $position => $object) {
			if ($object['schema'] !== 'case' || ($object['lookups']['caseType']['where']['identifier'] ?? null) !== 'omgevingsvergunning') {
				continue;
			}

			$cases++;
			$this->assertGreaterThan(expected: $last, actual: $position, message: $object['key']);
			$answers = array_column($object['data']['properties'] ?? [], null, 'name');
			$this->assertArrayHasKey(key: 'locatie', array: $answers, message: $object['key']);
			$this->assertNotSame(expected: '', actual: $answers['locatie']['value']);
			if (isset($object['data']['requester']) === true) {
				$this->assertArrayHasKey(key: 'aanvrager', array: $answers, message: $object['key']);
			}

			foreach ($answers as $name => $answer) {
				$this->assertSame(expected: '{{object:'.$fields[$name]['key'].'.id}}', actual: $answer['propertyDefinition']);
			}
		}

		$this->assertSame(expected: 6, actual: $cases);
	}//end testTheParentsCasesAnswerTheRequiredFields()

	/**
	 * Own and inherited fields interleave in the board's order on version 5.
	 *
	 * @return void
	 */
	public function testFieldsFollowTheBoardOrder(): void {
		$set   = $this->set();
		$order = [];
		foreach ($set['objects'] as $object) {
			$caseType = ($object['data']['caseType'] ?? null);
			if ($object['schema'] === 'propertyDefinition'
				&& ($caseType === '{{object:zaaktype-omgevingsvergunning-regulier.id}}'
				|| isset($this->parentFields(set: $set)[$object['data']['name']]) === true)
			) {
				$order[$object['data']['order']] = $object['data']['name'];
			}
		}

		ksort($order);
		$this->assertSame(
			expected: ['bouwkosten', 'soortBouwwerk', 'locatie', 'aanvrager', 'welstandsgebied', 'datumIndieningDso'],
			actual: array_slice(array_values($order), 0, 6)
		);
		$this->assertCount(expectedCount: 10, haystack: $order);
	}//end testFieldsFollowTheBoardOrder()
}//end class
