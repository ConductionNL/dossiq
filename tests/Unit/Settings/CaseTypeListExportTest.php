<?php

/**
 * The case type list can export its selection: the caseType schema is
 * exportable and the list allows its export, which nextcloud-vue's
 * `export-selected` needs before it is offered.
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
 * @spec openspec/specs/case-types/spec.md#requirement-the-case-type-list-exports-its-selection
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * The case type list's selection export is allowed.
 *
 * @spec openspec/specs/case-types/spec.md#requirement-the-case-type-list-exports-its-selection
 */
class CaseTypeListExportTest extends TestCase {

	/**
	 * The app root.
	 *
	 * @return string
	 */
	private function root(): string {
		return dirname(path: __DIR__, levels: 3);
	}//end root()

	/**
	 * The caseType schema is exportable, at 1.34.0 or later so it re-imports.
	 *
	 * @return void
	 */
	public function testTheCaseTypeSchemaIsExportable(): void {
		$base     = json_decode(json: (string)file_get_contents(filename: $this->root().'/lib/Settings/dossiq_register.json'), associative: true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $this->root().'/lib/Settings/register.d');
		$caseType = $merged['components']['schemas']['caseType'];

		$this->assertTrue(condition: ($caseType['configuration']['exportable'] ?? false) === true);
		$this->assertTrue(
			condition: version_compare($caseType['version'], '1.34.0', '>='),
			message: 'caseType reaches 1.34.0, its number in the version register'
		);
	}//end testTheCaseTypeSchemaIsExportable()

	/**
	 * The simple profile's case type list allows its export.
	 *
	 * @return void
	 */
	public function testTheCaseTypeListAllowsItsExport(): void {
		$layout = json_decode(json: (string)file_get_contents(filename: $this->root().'/src/menu-layout.simple.json'), associative: true);
		$page   = array_values(array_filter(array: $layout['pages'], callback: static fn (array $p): bool => ($p['id'] ?? '') === 'CaseTypes'))[0];

		$this->assertTrue(condition: ($page['config']['allowExport'] ?? false) === true);
	}//end testTheCaseTypeListAllowsItsExport()
}//end class
