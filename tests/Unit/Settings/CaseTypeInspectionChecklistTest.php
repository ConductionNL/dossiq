<?php

/**
 * A case type names the inspection checklist its cases are inspected with, and
 * the VTH package names one on its supervision case type, so the case page can
 * place the checklist by what the case type declares rather than by its name.
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
 * @spec openspec/changes/vth-cases-run-end-to-end
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The `inspectionChecklist` case type property and the VTH package's value for it.
 */
class CaseTypeInspectionChecklistTest extends TestCase {

	/**
	 * The repository root.
	 *
	 * @return string The path.
	 */
	private function root(): string {
		return dirname(__DIR__, 3);
	}//end root()

	/**
	 * One JSON file, decoded.
	 *
	 * @param string $path The path under the repository root.
	 *
	 * @return array<string, mixed> The decoded file.
	 */
	private function json(string $path): array {
		return (array)json_decode((string)file_get_contents($this->root() . '/' . $path), true);
	}//end json()

	/**
	 * The case type schema carries the property, as a string with a title.
	 *
	 * @return void
	 */
	public function testTheCaseTypeSchemaNamesAnInspectionChecklist(): void {
		$fragment = $this->json(path: 'lib/Settings/register.d/99-case-type-inspection-checklist.json');
		$caseType = (array)$fragment['components']['schemas']['caseType'];

		$this->assertSame(['inspectionChecklist'], array_keys((array)$caseType['properties']));
		$this->assertSame('string', $caseType['properties']['inspectionChecklist']['type']);
		$this->assertSame('Inspection checklist', $caseType['properties']['inspectionChecklist']['title']);
		$this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', (string)$caseType['version']);
	}//end testTheCaseTypeSchemaNamesAnInspectionChecklist()

	/**
	 * The VTH package names a checklist it ships on its supervision case type,
	 * and names none on a case type that is not inspected.
	 *
	 * @return void
	 */
	public function testTheVthPackageNamesAChecklistItShipsOnTheSupervisionCaseType(): void {
		$caseTypes = array_column((array)$this->json(path: 'lib/Settings/packages/vth/case-type.json')['caseType'], null, 'identifier');
		$checklists = array_column((array)$this->json(path: 'lib/Settings/packages/vth/seed/inspection-checklists.json')['rows'], null, 'id');

		$named = (string)($caseTypes['toezichtzaak-bouw']['inspectionChecklist'] ?? '');
		$this->assertArrayHasKey($named, $checklists, 'the checklist a case type names must be one the package installs');
		$this->assertSame('Bouwtoezicht fase 1 - Fundering', $checklists[$named]['name']);
		$this->assertSame(
			$caseTypes['toezichtzaak-bouw']['id'],
			$checklists[$named]['caseType'],
			'the named checklist must be one written for this case type'
		);

		foreach (['omgevingsvergunning-bouwactiviteit', 'sloopmelding', 'handhavingszaak', 'invorderingszaak'] as $identifier) {
			$this->assertArrayNotHasKey('inspectionChecklist', $caseTypes[$identifier], $identifier);
		}
	}//end testTheVthPackageNamesAChecklistItShipsOnTheSupervisionCaseType()

	/**
	 * The case page places the checklist on a case whose case type names one, by
	 * the property and never by the case type's name.
	 *
	 * @return void
	 */
	public function testTheCasePagePlacesTheChecklistByThePropertyTheCaseTypeDeclares(): void {
		$pages = array_column((array)$this->json(path: 'src/manifest.json')['pages'], null, 'id');
		$widgets = array_column((array)$pages['CaseDetail']['config']['widgets'], null, 'id');

		$widget = (array)$widgets['case-inspection-checklist'];
		$this->assertSame('checklist-run', $widget['type']);
		$this->assertSame(
			['field' => '@context.caseType.inspectionChecklist', 'op' => 'notEmpty'],
			$widget['visibleWhen']
		);
		$this->assertSame(
			['register' => 'dossiq', 'schema' => 'inspectionChecklistRun', 'filter' => ['case' => '@objectId'], 'sort' => ['field' => 'startedAt', 'dir' => 'desc']],
			$widget['content']['record']
		);
	}//end testTheCasePagePlacesTheChecklistByThePropertyTheCaseTypeDeclares()
}//end class
