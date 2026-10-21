<?php

/**
 * The case schema ships the two macros the board DqZaakAssistent draws, as
 * declared actions bound to manual flows the same schema declares by name, so
 * the library macros card lists them on every case.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Flow;

use OCA\Dossiq\Tests\Unit\Fixtures\ShippedRegisterSchema;
use PHPUnit\Framework\TestCase;

class CaseMacroDeclarationTest extends TestCase {

	/**
	 * The case schema's configuration.
	 *
	 * @return array<string, mixed>
	 */
	private function configuration(): array {
		$register = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json'), true);
		return $register['components']['schemas']['case']['configuration'];
	}//end configuration()

	public function testItDeclaresTheBoardsTwoMacros(): void {
		$actions = $this->configuration()['x-openregister-action'];
		$this->assertSame(['finish-woo-request', 'suspend-for-supplement'], array_keys($actions));
		foreach ($actions as $action) {
			$this->assertTrue($action['macro']);
			$this->assertNotSame('', $action['name']);
			$this->assertNotSame('', $action['description']);
		}
	}//end testItDeclaresTheBoardsTwoMacros()

	public function testEveryMacroNamesADeclaredFlowWithAManualTrigger(): void {
		$configuration = $this->configuration();
		$flows = [];
		foreach ($configuration['x-openregister-flows'] as $flow) {
			$flows[$flow['name']] = $flow;
		}

		foreach ($configuration['x-openregister-action'] as $key => $action) {
			$this->assertArrayHasKey($action['flow'], $flows, $key . ' names a flow the schema declares');
			$types = array_column($flows[$action['flow']]['nodes'], 'type');
			$this->assertContains('openregister.trigger-manual', $types, $key . ' starts by hand');
		}
	}//end testEveryMacroNamesADeclaredFlowWithAManualTrigger()

	public function testEveryChangingStepIsLabelledForTheLastRunLine(): void {
		foreach ($this->configuration()['x-openregister-flows'] as $flow) {
			if (($flow['trigger'] ?? '') !== 'manual') {
				continue;
			}

			foreach ($flow['nodes'] as $node) {
				if (str_starts_with($node['type'], 'dossiq.') === true) {
					$this->assertNotSame('', ($node['label'] ?? ''), $flow['name'] . ': ' . $node['id']);
				}
			}

			// Edges join every node, start to end.
			$ids = array_column($flow['nodes'], 'id');
			foreach ($flow['edges'] as $edge) {
				$this->assertContains($edge['from'], $ids);
				$this->assertContains($edge['to'], $ids);
			}
		}
	}//end testEveryChangingStepIsLabelledForTheLastRunLine()

	public function testTheCaseTypeDeclaresWhichMacrosItOffers(): void {
		$fragment = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/99-case-type-which-macros.json'), true);
		$macros = $fragment['components']['schemas']['caseType']['properties']['macros'];
		$this->assertSame('array', $macros['type']);
		$this->assertSame('string', $macros['items']['type']);
	}//end testTheCaseTypeDeclaresWhichMacrosItOffers()

	public function testTheWooRequestOffersBothMacrosAndEveryListedKeyIsDeclared(): void {
		$seed = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/register.d/81-woo-verzoek.json'), true);
		$listed = $seed['components']['objects'][0]['macros'];
		$this->assertSame(['finish-woo-request', 'suspend-for-supplement'], $listed);
		$declared = array_keys($this->configuration()['x-openregister-action']);
		foreach ($listed as $key) {
			$this->assertContains($key, $declared);
		}
	}//end testTheWooRequestOffersBothMacrosAndEveryListedKeyIsDeclared()

	public function testWhoeverMayEditTheCaseMayRunItsMacros(): void {
		$case = ShippedRegisterSchema::schema('case');
		$rules = $case['authorization'];
		foreach (array_keys($this->configuration()['x-openregister-action']) as $key) {
			$this->assertArrayHasKey($key, $rules, $key . ' carries a rule of its own, not OpenRegister\'s default');
			$this->assertSame($rules['update'], $rules[$key], $key . ' follows the case\'s update rule');
		}
	}//end testWhoeverMayEditTheCaseMayRunItsMacros()

	public function testTheSuspensionGoesThroughTheTermNode(): void {
		foreach ($this->configuration()['x-openregister-flows'] as $flow) {
			if ($flow['name'] === 'Suspend for supplementary information') {
				$this->assertContains('dossiq.suspendTerm', array_column($flow['nodes'], 'type'));
				return;
			}
		}

		$this->fail('The suspension macro\'s flow is not declared.');
	}//end testTheSuspensionGoesThroughTheTermNode()
}//end class
