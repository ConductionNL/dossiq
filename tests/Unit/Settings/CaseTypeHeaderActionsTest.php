<?php

/**
 * A case type declares the header row of its cases.
 *
 * The caseType schema carries `headerActions` (an ordered list of header
 * action ids) and `primaryAction` (the one drawn as the primary button). Both
 * are optional, so a case type that declares neither keeps the page's row.
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
 * @spec openspec/changes/case-type-declares-its-header-actions/specs/case-type-header-actions/spec.md#requirement-a-case-type-declares-the-header-row-of-its-cases
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use OCA\Dossiq\Service\Settings\RegisterFragmentMerger;
use PHPUnit\Framework\TestCase;

/**
 * The caseType schema declares the header row keys.
 */
class CaseTypeHeaderActionsTest extends TestCase {

	/**
	 * The caseType schema as an instance imports it: the base with every fragment.
	 *
	 * @return array<string, mixed> The merged caseType schema.
	 */
	private function caseType(): array {
		$root = dirname(__DIR__, 3);
		$base = json_decode((string)file_get_contents($root . '/lib/Settings/dossiq_register.json'), true);
		[$merged] = (new RegisterFragmentMerger())->merge(base: $base, fragmentDir: $root . '/lib/Settings/register.d');

		return $merged['components']['schemas']['caseType'];
	}//end caseType()

	/**
	 * The header actions are an optional, ordered list of distinct ids.
	 *
	 * @return void
	 */
	public function testHeaderActionsIsAnOptionalListOfIds(): void {
		$schema   = $this->caseType();
		$property = ($schema['properties']['headerActions'] ?? null);

		$this->assertIsArray(actual: $property, message: 'caseType declares headerActions, or OpenRegister stores it nowhere');
		$this->assertSame(expected: 'array', actual: $property['type']);
		$this->assertSame(expected: 'string', actual: $property['items']['type']);
		$this->assertTrue(condition: $property['uniqueItems']);
		$this->assertSame(expected: [], actual: $property['default']);
		$this->assertNotContains(needle: 'headerActions', haystack: ($schema['required'] ?? []), message: 'a case type without a row keeps the page row');
	}//end testHeaderActionsIsAnOptionalListOfIds()

	/**
	 * The primary action is an optional single id.
	 *
	 * @return void
	 */
	public function testPrimaryActionIsAnOptionalId(): void {
		$schema   = $this->caseType();
		$property = ($schema['properties']['primaryAction'] ?? null);

		$this->assertIsArray(actual: $property, message: 'caseType declares primaryAction, or OpenRegister stores it nowhere');
		$this->assertSame(expected: 'string', actual: $property['type']);
		$this->assertNotContains(
			needle: 'primaryAction',
			haystack: ($schema['required'] ?? []),
			message: 'a case type without a primary keeps the page primary'
		);
	}//end testPrimaryActionIsAnOptionalId()

	/**
	 * The schema version moves past 1.51.0 so an existing instance re-imports it.
	 *
	 * @return void
	 */
	public function testTheCaseTypeVersionMovesSoInstancesReimport(): void {
		$this->assertTrue(condition: version_compare(version1: (string)$this->caseType()['version'], version2: '1.52.0', operator: '>='));
	}//end testTheCaseTypeVersionMovesSoInstancesReimport()
}//end class
