<?php

/**
 * A case type opts into advice from a decision table by naming the table,
 * and the handhavingszaak the vth package ships names the LHS intervention
 * table the lhs-intervention package installs.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * The `adviceDecisionTable` property and the case types that set it.
 *
 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
 */
class AdviceDecisionTableConfigTest extends TestCase {

	/**
	 * Read one JSON file under the repository root.
	 *
	 * @param string $path The path, relative to the root.
	 *
	 * @return array<string, mixed>
	 */
	private function json(string $path): array {
		return json_decode((string)file_get_contents(dirname(__DIR__, 3).'/'.$path), true);
	}//end json()

	/**
	 * The case type schema of the merged register.
	 *
	 * @return array<string, mixed>
	 */
	private function caseTypeSchema(): array {
		[$merged] = (new RegisterFragmentMerger())->merge(
			base: $this->json(path: 'lib/Settings/dossiq_register.json'),
			fragmentDir: dirname(__DIR__, 3).'/lib/Settings/register.d'
		);

		return $merged['components']['schemas']['caseType'];
	}//end caseTypeSchema()

	/**
	 * The vth package's case types by identifier.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function vthCaseTypes(): array {
		return array_column($this->json(path: 'lib/Settings/packages/vth/case-type.json')['caseType'], null, 'identifier');
	}//end vthCaseTypes()

	/**
	 * The case type declares the table as a plain string that is empty unless
	 * set, so a case type that says nothing offers no advice step.
	 *
	 * @return void
	 */
	public function testTheCaseTypeNamesItsTableAndIsEmptyByDefault(): void {
		$property = $this->caseTypeSchema()['properties']['adviceDecisionTable'];

		$this->assertSame(expected: 'string', actual: $property['type']);
		$this->assertSame(expected: '', actual: $property['default']);
		$this->assertSame(expected: 'Advice decision table', actual: $property['title']);
		$this->assertNotContains(needle: 'adviceDecisionTable', haystack: ($this->caseTypeSchema()['required'] ?? []));
	}//end testTheCaseTypeNamesItsTableAndIsEmptyByDefault()

	/**
	 * The handhavingszaak names the table the lhs-intervention package
	 * installs, by its key.
	 *
	 * @return void
	 */
	public function testTheHandhavingszaakNamesTheShippedInterventionTable(): void {
		$table = $this->json(path: 'lib/Settings/packages/lhs-intervention/seed/table.json')['rows'][0];

		$this->assertSame(expected: $table['key'], actual: $this->vthCaseTypes()['handhavingszaak']['adviceDecisionTable']);
	}//end testTheHandhavingszaakNamesTheShippedInterventionTable()

	/**
	 * Only the enforcement case type asks for advice; the permit, demolition,
	 * supervision and collection case types name no table.
	 *
	 * @return void
	 */
	public function testNoOtherVthCaseTypeAsksForAdvice(): void {
		$naming = [];
		foreach ($this->vthCaseTypes() as $identifier => $caseType) {
			if (($caseType['adviceDecisionTable'] ?? '') !== '') {
				$naming[] = $identifier;
			}
		}

		$this->assertSame(expected: ['handhavingszaak'], actual: $naming);
	}//end testNoOtherVthCaseTypeAsksForAdvice()

	/**
	 * The vth package moved past 1.3.1, so an instance that installed it
	 * re-applies the case type with the table set.
	 *
	 * @return void
	 */
	public function testTheVthPackageVersionMoved(): void {
		$version = $this->json(path: 'lib/Settings/packages/vth/package.json')['version'];

		$this->assertTrue(condition: version_compare($version, '1.3.1', '>'));
	}//end testTheVthPackageVersionMoved()
}//end class
