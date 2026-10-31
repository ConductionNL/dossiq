<?php

/**
 * Example data for DqContracten: the six contracts the board names among 34,
 * with the four dossiq ships the 38 the board counts, in every status, and a
 * contract status named by its label.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/example-set-supplier-contracts/spec.md#requirement-the-zuiddrecht-set-has-supplier-contracts-in-every-status
 * @spec openspec/specs/example-set-supplier-contracts/spec.md#requirement-a-contract-status-is-named-by-its-label
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The contracts board's suppliers and contracts.
 *
 * @spec openspec/specs/example-set-supplier-contracts/spec.md#requirement-the-zuiddrecht-set-has-supplier-contracts-in-every-status
 */
class ZuiddrechtSupplierContractsTest extends TestCase {

	/**
	 * The set's objects, by key.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function objects(): array {
		$set = json_decode(json: (string)file_get_contents(filename: __DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), associative: true);

		return array_column(array: $set['objects'], column_key: null, index_key: 'key');
	}//end objects()

	/**
	 * Six contracts as the board draws them, each on a supplier of the set.
	 *
	 * @return void
	 */
	public function testTheBoardsContractsAreInTheSet(): void {
		$objects   = $this->objects();
		$contracts = [];
		foreach ($objects as $object) {
			if ($object['schema'] !== 'supplierContract') {
				continue;
			}

			$supplierKey = preg_replace(pattern: '/^\{\{object:(.+)\.id\}\}$/', replacement: '$1', subject: (string)$object['data']['supplierRef']);
			$this->assertSame(
				expected: 'supplier',
				actual: ($objects[$supplierKey]['schema'] ?? null),
				message: $object['key'].' points at a supplier of the set'
			);

			$contracts[$object['data']['number']] = [
				$object['data']['subject'],
				$objects[$supplierKey]['data']['legalName'],
				$object['data']['status'],
			];
		}

		$named = array_intersect_key($contracts, array_flip(['CT-2022-021', 'CT-2023-014', 'CT-2024-003', 'CT-2024-011', 'CT-2025-002', 'CT-2025-009']));
		ksort(array: $named);
		$this->assertSame(
			expected: [
				'CT-2022-021' => ['Leerlingenvervoer', 'Taxi Drechtstad', 'ending'],
				'CT-2023-014' => ['Jeugdhulp ambulant 2023-2026', 'Stichting Kindzorg Drechtsteden', 'renewal-decision'],
				'CT-2024-003' => ['Groenonderhoud oost', 'Groen & Co', 'active'],
				'CT-2024-011' => ['Schoonmaak stadhuis', 'Schoon Zuid', 'termination-decision'],
				'CT-2025-002' => ['Straatverlichting onderhoud', 'Lumen Infra BV', 'active'],
				'CT-2025-009' => ['Softwarelicenties sociaal domein', 'Conduction', 'active'],
			],
			actual: $named
		);
	}//end testTheBoardsContractsAreInTheSet()

	/**
	 * With the contracts dossiq ships, the list counts what the board draws:
	 * 38 contracts, 29 active, 2 waiting on a decision, 7 ended, 5 with a
	 * renewal warning.
	 *
	 * @return void
	 */
	public function testTheListCountsWhatTheBoardDraws(): void {
		$register = json_decode(json: (string)file_get_contents(filename: __DIR__.'/../../../lib/Settings/dossiq_register.json'), associative: true);
		$shipped  = array_filter(
			array: $register['components']['objects'],
			callback: static fn (array $o): bool => ($o['@self']['schema'] ?? '') === 'supplierContract'
		);
		$seeded   = array_map(
			callback: static fn (array $o): array => $o['data'],
			array: array_filter(array: $this->objects(), callback: static fn (array $o): bool => $o['schema'] === 'supplierContract')
		);
		$all      = array_merge(array_values($shipped), array_values($seeded));

		$statuses = array_count_values(array_column(array: $all, column_key: 'status'));
		ksort(array: $statuses);
		$this->assertCount(expectedCount: 38, haystack: $all, message: 'every contract has a status');
		$this->assertSame(
			expected: ['active' => 29, 'ending' => 7, 'renewal-decision' => 1, 'termination-decision' => 1],
			actual: $statuses
		);
		$warned = array_filter(array: $all, callback: static fn (array $c): bool => ($c['renewalWarning'] ?? false) === true);
		$this->assertCount(expectedCount: 5, haystack: $warned);

		$this->assertTrue(
			condition: version_compare($register['info']['version'], '0.20.64', '>'),
			message: 'the register version moves past 0.20.64, so the shipped contracts take their status on an existing instance'
		);

		$numbers = array_column(array: $all, column_key: 'number');
		$this->assertSame(expected: $numbers, actual: array_values(array_unique($numbers)), message: 'contract numbers are unique');
	}//end testTheListCountsWhatTheBoardDraws()

	/**
	 * A supplier's KvK number is in the example range, never a real company's.
	 *
	 * @return void
	 */
	public function testSupplierKvkNumbersAreInTheExampleRange(): void {
		$suppliers = array_filter(array: $this->objects(), callback: static fn (array $o): bool => $o['schema'] === 'supplier');

		$this->assertCount(expectedCount: 18, haystack: $suppliers, message: 'with the three dossiq ships, 21 suppliers');
		foreach ($suppliers as $supplier) {
			$this->assertMatchesRegularExpression(pattern: '/^000020\d\d$/', string: $supplier['data']['kvkNumber'], message: $supplier['key']);
		}
	}//end testSupplierKvkNumbersAreInTheExampleRange()

	/**
	 * Every status value has a oneOf label equal to its x-enum-labels text, at version 1.3.0.
	 *
	 * @return void
	 */
	public function testEveryContractStatusHasALabel(): void {
		$path     = __DIR__.'/../../../lib/Settings/register.d/96-supplier-contract-status.json';
		$fragment = json_decode(json: (string)file_get_contents(filename: $path), associative: true);
		$schema   = $fragment['components']['schemas']['supplierContract'];
		$status   = $schema['properties']['status'];

		$this->assertSame(expected: '1.3.0', actual: $schema['version']);
		$this->assertSame(
			expected: $status['x-enum-labels'],
			actual: array_column(array: $status['oneOf'] ?? [], column_key: 'title', index_key: 'const')
		);
		$this->assertSame(expected: $status['enum'], actual: array_keys($status['x-enum-labels']));
	}//end testEveryContractStatusHasALabel()
}//end class
