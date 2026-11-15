<?php

/**
 * Termijn en betaling reads as the board draws it: "Wettelijke termijn: 4
 * weken, te verdagen met 2" and "Laatst gecontroleerd". The case carries its
 * type's extension period beside the term, and the card names both.
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
 * @spec openspec/specs/case-management/spec.md#requirement-the-term-row-reads-in-words-with-the-extension-the-case-type-allows
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The extension period on the case and the term card's overrides.
 */
class CaseTermRowReadsAsTheBoardTest extends TestCase {

	/**
	 * A JSON file under the app root.
	 *
	 * @param string $path The path from the app root.
	 *
	 * @return array<string, mixed>
	 */
	private static function json(string $path): array {
		return json_decode((string)file_get_contents(__DIR__.'/../../../'.$path), true);
	}//end json()

	/**
	 * The case copies its type's extension period, materialised as statutoryTerm is.
	 *
	 * @return void
	 */
	public function testTheCaseCarriesItsTypesExtensionPeriod(): void {
		$case = self::json(path: 'lib/Settings/register.d/99-case-statutory-extension.json')['components']['schemas']['case'];
		$calc = $case['configuration']['x-openregister-calculations']['statutoryExtension'];

		$this->assertTrue(condition: $case['properties']['statutoryExtension']['readOnly']);
		$this->assertTrue(condition: $calc['materialise']);
		$this->assertSame(expected: ['prop' => '@ref.caseType.extensionPeriod'], actual: $calc['expression']);
	}//end testTheCaseCarriesItsTypesExtensionPeriod()

	/**
	 * The term card reads the term in words with the extension, and both labels are the board's.
	 *
	 * @return void
	 */
	public function testTheTermCardUsesTheBoardsLabelsAndWords(): void {
		$layout = (string)file_get_contents(__DIR__.'/../../../src/menu-layout.simple.json');
		$card   = null;
		$find   = static function ($node) use (&$find, &$card): void {
			if (is_array($node) === false) {
				return;
			}

			if (isset($node['case-terms']) === true) {
				$card = $node['case-terms'];
				return;
			}

			foreach ($node as $child) {
				$find($child);
			}
		};
		$find(json_decode($layout, true));

		$overrides = $card['content']['overrides'];
		$this->assertSame(expected: 'duration', actual: $overrides['statutoryTerm']['format']);
		$this->assertSame(expected: 'statutoryExtension', actual: $overrides['statutoryTerm']['extendBy']);

		$nl = self::json(path: 'l10n/nl.json')['translations'];
		$this->assertSame(expected: 'Wettelijke termijn', actual: $nl[$overrides['statutoryTerm']['label']]);
		$this->assertSame(expected: 'Laatst gecontroleerd', actual: $nl[$overrides['paymentStateCheckedAt']['label']]);
		$this->assertSame(expected: 'Rechtsgrond', actual: $nl[$overrides['legalBasis']['label']]);

		$order = array_map(static fn (array $override): int => (int)($override['order'] ?? 99), $overrides);
		asort($order);
		$board = ['statutoryTerm', 'legalBasis', 'paymentState', 'paymentStateCheckedAt'];
		$this->assertSame(expected: $board, actual: array_keys($order), message: 'the board order');
	}//end testTheTermCardUsesTheBoardsLabelsAndWords()
}//end class
