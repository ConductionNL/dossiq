<?php

/**
 * The payment state on a case page reads as words ("Geen leges voor dit
 * zaaktype"), not as the stored code.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Tests
 * @package   OCA\Dossiq\Tests\Unit\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/specs/case-management/spec.md#requirement-a-cases-payment-state-reads-as-words
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Every payment state has a label with Dutch.
 */
class CasePaymentStateLabelsTest extends TestCase {

	/**
	 * Each stored payment state has a label, and each label has Dutch.
	 *
	 * @return void
	 */
	public function testEveryPaymentStateReadsAsWordsInDutch(): void {
		$root     = __DIR__.'/../../../';
		$register = json_decode((string)file_get_contents($root.'lib/Settings/dossiq_register.json'), true);
		$fragment = json_decode((string)file_get_contents($root.'lib/Settings/register.d/99-case-payment-state-labels.json'), true);
		$nl       = json_decode((string)file_get_contents($root.'l10n/nl.json'), true)['translations'];

		$values = $register['components']['schemas']['case']['properties']['paymentState']['enum'];
		$labels = $fragment['components']['schemas']['case']['properties']['paymentState']['x-enum-labels'];
		$this->assertSame(expected: $values, actual: array_keys($labels));
		foreach ($labels as $label) {
			$this->assertNotEmpty(actual: ($nl[$label] ?? ''), message: $label.' has no Dutch');
		}

		$this->assertSame(expected: 'Geen leges voor dit zaaktype', actual: $nl[$labels['notRequired']]);
		$moved = version_compare($fragment['components']['schemas']['case']['version'], $register['components']['schemas']['case']['version'], '>');
		$this->assertTrue(condition: $moved, message: 'the case schema version moves so an instance re-imports');
	}//end testEveryPaymentStateReadsAsWordsInDutch()
}//end class
