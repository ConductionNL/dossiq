<?php

/**
 * Unit tests for PackageAdminTab: a package's `adminTab` declaration is read
 * into a title and its lists, and a malformed one is refused with a message
 * per problem, both on its own and through the shipped package rules.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/vth-cases-run-end-to-end
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseDefinition;

use OCA\Dossiq\Service\CaseDefinition\PackageAdminTab;
use OCA\Dossiq\Service\CaseDefinition\ShippedPackageRules;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\CaseDefinition\PackageAdminTab
 * @covers \OCA\Dossiq\Service\CaseDefinition\ShippedPackageRules
 *
 * @uses \OCA\Dossiq\Service\CaseDefinition\PackagePartRules
 * @uses \OCA\Dossiq\Service\CaseDefinition\PackageSettingRules
 */
class PackageAdminTabTest extends TestCase {

	public function testAWellFormedTabIsReadWithItsListsOnceEachInDeclaredOrder(): void {
		$tab = (new PackageAdminTab())->parse(
			['id' => 'vth', 'adminTab' => ['title' => ' VTH ', 'lists' => ['flows', 'caseTypes', 'flows', 'checklists']]]
		);

		$this->assertSame(['title' => 'VTH', 'lists' => ['flows', 'caseTypes', 'checklists']], $tab);
	}//end testAWellFormedTabIsReadWithItsListsOnceEachInDeclaredOrder()

	public function testAManifestWithoutATabHasNone(): void {
		$adminTab = new PackageAdminTab();

		$this->assertNull($adminTab->parse(['id' => 'subsidie']));
		$this->assertSame([], $adminTab->errors(null));
	}//end testAManifestWithoutATabHasNone()

	public function testAMalformedTabIsNotReadAsATab(): void {
		$this->assertNull((new PackageAdminTab())->parse(['adminTab' => ['title' => 'VTH', 'lists' => ['payments']]]));
	}//end testAMalformedTabIsNotReadAsATab()

	public function testEveryListNameIsAccepted(): void {
		$lists = ['caseTypes', 'flows', 'feeRules', 'templates', 'connections', 'checklists'];

		$this->assertSame($lists, array_keys(PackageAdminTab::LISTS));
		$this->assertSame([], (new PackageAdminTab())->errors(['title' => 'All', 'lists' => $lists]));
	}//end testEveryListNameIsAccepted()

	public function testAnAdminTabThatIsNotAnObjectIsRefused(): void {
		$expected = ['package.json: adminTab must be an object with a title and lists.'];

		$this->assertSame($expected, (new PackageAdminTab())->errors('VTH'));
		$this->assertSame($expected, (new PackageAdminTab())->errors(['VTH', 'flows']));
	}//end testAnAdminTabThatIsNotAnObjectIsRefused()

	public function testAMissingTitleAndMissingListsAreBothReported(): void {
		$this->assertSame(
			['package.json: adminTab.title is missing.', 'package.json: adminTab.lists must be a non-empty list.'],
			(new PackageAdminTab())->errors(['title' => '  '])
		);
		$this->assertSame(
			['package.json: adminTab.lists must be a non-empty list.'],
			(new PackageAdminTab())->errors(['title' => 'VTH', 'lists' => []])
		);
		$this->assertSame(
			['package.json: adminTab.lists must be a non-empty list.'],
			(new PackageAdminTab())->errors(['title' => 'VTH', 'lists' => ['a' => 'flows']])
		);
	}//end testAMissingTitleAndMissingListsAreBothReported()

	public function testEachUnknownListIsReportedByName(): void {
		$this->assertSame(
			[
				"package.json: adminTab list 'payments' is not one of caseTypes, flows, feeRules, templates, connections, checklists.",
				"package.json: adminTab list 'integer' is not one of caseTypes, flows, feeRules, templates, connections, checklists.",
			],
			(new PackageAdminTab())->errors(['title' => 'VTH', 'lists' => ['flows', 'payments', 3]])
		);
	}//end testEachUnknownListIsReportedByName()

	public function testTheShippedPackageRulesRefuseAMalformedTab(): void {
		$manifest = ['id' => 'vth', 'title' => 'VTH', 'version' => '1.0.0', 'requires' => [], 'parts' => ['settings'], 'adminTab' => ['title' => 'VTH', 'lists' => ['payments']]];

		$result = (new ShippedPackageRules())->check(
			['id' => 'vth', 'files' => ['package.json' => json_encode($manifest), 'settings.json' => '{"case_schema": "zaak_schema"}']]
		);

		$this->assertFalse($result['valid']);
		$this->assertSame(
			["package.json: adminTab list 'payments' is not one of caseTypes, flows, feeRules, templates, connections, checklists."],
			$result['errors']
		);
	}//end testTheShippedPackageRulesRefuseAMalformedTab()

	public function testTheShippedPackageRulesAcceptAWellFormedTab(): void {
		$manifest = ['id' => 'vth', 'title' => 'VTH', 'version' => '1.0.0', 'requires' => [], 'parts' => ['settings'], 'adminTab' => ['title' => 'VTH', 'lists' => ['flows']]];

		$result = (new ShippedPackageRules())->check(
			['id' => 'vth', 'files' => ['package.json' => json_encode($manifest), 'settings.json' => '{"case_schema": "zaak_schema"}']]
		);

		$this->assertSame([], $result['errors']);
		$this->assertTrue($result['valid']);
	}//end testTheShippedPackageRulesAcceptAWellFormedTab()
}//end class
