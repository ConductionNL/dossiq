<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Tests
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The case values a handler reads on the case page carry a label.
 *
 * Rehearsal R2 (15 Oct) showed "Afgeleide prioriteit normal", "Woo-publicatie
 * ready" and "Intakekanaal website": a value without `x-enum-labels` is shown
 * as the stored code, which no catalogue translates. Each label is an English
 * catalogue key with a Dutch entry.
 *
 * @spec openspec/specs/case-management/spec.md#requirement-the-case-page-shows-labels-and-names-not-codes-req-cm-82
 */
class CaseEnumLabelsTest extends TestCase {

	/**
	 * The case schema's properties, the register fragments merged on top.
	 *
	 * @return array<string, array<string, mixed>> The properties.
	 */
	private function caseProperties(): array {
		$root = __DIR__ . '/../../../lib/Settings';
		$register = json_decode((string)file_get_contents($root . '/dossiq_register.json'), true);
		$properties = $register['components']['schemas']['case']['properties'];
		$fragment = json_decode((string)file_get_contents($root . '/register.d/82-woo-publication-on-the-case.json'), true);
		foreach ($fragment['components']['schemas']['case']['properties'] as $key => $property) {
			$properties[$key] = array_merge(($properties[$key] ?? []), $property);
		}

		return $properties;
	}//end caseProperties()

	/**
	 * Each of these enums labels every value, and every label has Dutch.
	 *
	 * @return void
	 */
	public function testTheCasePageEnumsLabelEveryValueInDutch(): void {
		$properties = $this->caseProperties();
		$nl = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/nl.json'), true)['translations'];

		foreach (['priorityDerived', 'priorityFloor', 'priorityOverride', 'intakeChannel', 'wooPublicationStatus'] as $key) {
			$labels = ($properties[$key]['x-enum-labels'] ?? []);
			foreach ($properties[$key]['enum'] as $value) {
				$this->assertArrayHasKey((string)$value, $labels, $key . ' has no label for ' . $value);
				$this->assertNotEmpty($nl[$labels[$value]] ?? '', $key . ': "' . $labels[$value] . '" has no Dutch');
			}
		}
	}//end testTheCasePageEnumsLabelEveryValueInDutch()

	/**
	 * Control: a stored code is not itself a catalogue key, so without a
	 * label the page would show it raw.
	 *
	 * @return void
	 */
	public function testTheStoredCodesAreNotCatalogueKeys(): void {
		$nl = json_decode((string)file_get_contents(__DIR__ . '/../../../l10n/nl.json'), true)['translations'];

		$this->assertArrayNotHasKey('normal', $nl);
		$this->assertArrayNotHasKey('ready', $nl);
	}//end testTheStoredCodesAreNotCatalogueKeys()
}//end class
