<?php

/**
 * Example data for the dossiq case boards on the Lindelaan Woo case: the
 * configuration its rows need from dossiq's half of the set. The Woo case
 * type points at its work instruction and declares the two actions planned
 * on the case, and an advice request has a case type of its own, so the
 * case can wait on one.
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
 * The configuration behind the Lindelaan case's Werkinstructie, Wat hierna komt and Wacht op rows.
 */
class ZuiddrechtLindelaanCaseConfigTest extends TestCase {

	/**
	 * The fragment.
	 *
	 * @return array<string, mixed> The decoded fragment.
	 */
	private function set(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);

		return $set;
	}//end set()

	/**
	 * The Woo case type points at the Collectives page that explains how the team handles it.
	 *
	 * @return void
	 */
	public function testTheWooCaseTypeNamesItsWorkInstruction(): void {
		$change = array_column($this->set()['changes'], null, 'key')['woo-verzoek-werkinstructie'];
		$this->assertSame(expected: 'caseType', actual: $change['schema']);
		$this->assertSame(expected: ['identifier' => 'woo-verzoek', 'version' => 2], actual: $change['where']);
		$this->assertSame(expected: 'Woo en informatie/Hoe we Woo-verzoeken behandelen', actual: $change['set']['knowledgeBasePage']);
	}//end testTheWooCaseTypeNamesItsWorkInstruction()

	/**
	 * The Woo case type declares the two action types the case plans next.
	 *
	 * @return void
	 */
	public function testTheWooCaseTypeDeclaresItsPlannedActions(): void {
		$types = array_values(
			array_filter(
				$this->set()['objects'],
				static fn (array $object): bool => $object['schema'] === 'plannedActionType'
					&& ($object['lookups']['caseType']['where'] ?? []) === ['identifier' => 'woo-verzoek', 'version' => 2]
			)
		);
		$this->assertSame(
			expected: ['besluit-opstellen' => 'Besluit opstellen', 'termijn-controleren' => 'Termijn controleren'],
			actual: array_column(array_column($types, 'data'), 'label', 'identifier')
		);
	}//end testTheWooCaseTypeDeclaresItsPlannedActions()

	/**
	 * An advice request is a case type with a start, a working and a final status, so a case can wait on one.
	 *
	 * @return void
	 */
	public function testAnAdviceRequestHasItsOwnCaseType(): void {
		$objects = array_column($this->set()['objects'], null, 'key');
		$type    = $objects['zaaktype-adviesaanvraag'];
		$this->assertSame(
			expected: ['caseType', 'adviesaanvraag', 'Adviesaanvraag'],
			actual: [$type['schema'], $type['data']['identifier'], $type['data']['title']]
		);

		$statuses = array_values(
			array_filter(
				$objects,
				static fn (array $object): bool => $object['schema'] === 'statusType'
					&& ($object['data']['caseType'] ?? '') === '{{object:zaaktype-adviesaanvraag.id}}'
			)
		);
		$this->assertSame(
			expected: [['Ontvangen', false], ['In behandeling', false], ['Afgehandeld', true]],
			actual: array_map(static fn (array $status): array => [$status['data']['name'], $status['data']['isFinal']], $statuses)
		);

		$start = array_column($this->set()['changes'], null, 'key')['zaaktype-adviesaanvraag-begint-bij-ontvangen'];
		$this->assertSame(expected: 'zaaktype-adviesaanvraag', actual: $start['object']);
		$this->assertSame(expected: '{{object:status-adviesaanvraag-ontvangen.id}}', actual: $start['set']['initialStatus']);
	}//end testAnAdviceRequestHasItsOwnCaseType()
}//end class
