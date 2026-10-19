<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The case type schema carries the fields the retired admin editor wrote,
 * and a sub-case type points at a case type.
 *
 * @coversNothing
 */
class CaseTypeAuthoringFieldsTest extends TestCase {

	/**
	 * @return array<string, mixed> The caseType schema.
	 */
	private function caseType(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'), true);

		return $register['components']['schemas']['caseType'];
	}//end caseType()

	/**
	 * @return void
	 */
	public function testTheEditorFieldsAreDeclaredAsText(): void {
		$properties = $this->caseType()['properties'];
		foreach (['initiatorAction', 'responsibleUnit', 'referenceProcess', 'keywords', 'publicationText'] as $field) {
			$this->assertArrayHasKey($field, $properties);
			$this->assertSame('string', $properties[$field]['type']);
			$this->assertArrayNotHasKey('format', $properties[$field], 'adding a format is a breaking schema change');
		}
	}//end testTheEditorFieldsAreDeclaredAsText()

	/**
	 * @return void
	 */
	public function testASubCaseTypeReferencesACaseType(): void {
		$this->assertSame('caseType', $this->caseType()['properties']['subCaseTypes']['items']['$ref']);
	}//end testASubCaseTypeReferencesACaseType()
}//end class
