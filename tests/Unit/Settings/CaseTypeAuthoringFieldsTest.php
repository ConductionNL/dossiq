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
		foreach (['initiatorAction', 'responsibleUnit', 'referenceProcess', 'keywords', 'publicationText', 'serviceTarget'] as $field) {
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

	/**
	 * The notification domain and the handling teams come from register.d
	 * fragments; they are declared there, so the main register must not
	 * declare them a second time.
	 *
	 * @return void
	 */
	public function testTheDomainAndTeamsAreDeclaredByTheirFragments(): void {
		$dir = __DIR__ . '/../../../lib/Settings/register.d/';
		$routing = json_decode((string)file_get_contents($dir . '66-notification-routing.json'), true);
		$starter = json_decode((string)file_get_contents($dir . '48-starter-content.json'), true);

		$domain = $routing['components']['schemas']['caseType']['properties']['notificationDomain'];
		$this->assertSame(['zaken', 'waarneming', 'werkvoorraad'], $domain['enum']);

		$teams = $starter['components']['schemas']['caseType']['properties']['handling']['properties']['teams'];
		$this->assertSame('array', $teams['type']);
		$this->assertSame('string', $teams['items']['type']);

		$properties = $this->caseType()['properties'];
		$this->assertArrayNotHasKey('notificationDomain', $properties);
		$this->assertArrayNotHasKey('handling', $properties);
	}//end testTheDomainAndTeamsAreDeclaredByTheirFragments()

	/**
	 * The service target and notification domain can be read on the case type
	 * page and filled in when a case type is created.
	 *
	 * @return void
	 */
	public function testTheFieldsAreOnTheCardsAndTheCreateForm(): void {
		$manifest = json_decode((string)file_get_contents(__DIR__ . '/../../../src/manifest.json'), true);
		$pages = array_column($manifest['pages'], null, 'id');

		$widgets = array_column($pages['CaseTypeDetail']['config']['widgets'], null, 'id');
		$this->assertContains('notificationDomain', $widgets['case-type-core']['content']['include']);
		$this->assertContains('serviceTarget', $widgets['case-type-terms']['content']['include']);

		$create = $pages['CaseTypes']['config']['includeFields'];
		$this->assertContains('serviceTarget', $create);
		$this->assertContains('notificationDomain', $create);
	}//end testTheFieldsAreOnTheCardsAndTheCreateForm()
}//end class
