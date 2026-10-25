<?php

/**
 * Example data for DqHandhaving and DqVthInstellingen: Zuiddrecht's own
 * enforcement matrix, version 2, with four consequence columns.
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
 * @spec exclude example-set data integrity; no requirement covers the demo fragment
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The Handhavingsmatrix Zuiddrecht.
 */
class ZuiddrechtLhsMatrixTest extends TestCase {

	/**
	 * The decoded Zuiddrecht fragment.
	 *
	 * @return array<string, mixed>
	 */
	private function set(): array {
		$set = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$this->assertIsArray(actual: $set);

		return $set;
	}//end set()

	/**
	 * Every severity, behaviour and actor type has exactly one cell.
	 *
	 * @return void
	 */
	public function testTheMatrixIsDense(): void {
		$matrix = array_column($this->set()['objects'], null, 'key')['handhavingsmatrix-zuiddrecht-v2']['data'];

		$this->assertSame(expected: ['Handhavingsmatrix Zuiddrecht', 2, true], actual: [$matrix['name'], $matrix['version'], $matrix['active']]);
		$this->assertSame(expected: ['gering', 'aanzienlijk', 'ernstig', 'zeer_ernstig'], actual: $matrix['severityAxis']);

		$seen = [];
		foreach ($matrix['cells'] as $cell) {
			$seen[$cell['severity'].'|'.$cell['behaviour'].'|'.$cell['actorType']] = true;
			$this->assertContains(needle: $cell['severity'], haystack: $matrix['severityAxis']);
			$this->assertContains(needle: $cell['behaviour'], haystack: $matrix['behaviourAxis']);
			$this->assertContains(needle: $cell['actorType'], haystack: $matrix['actorTypeAxis']);
		}

		$expected = count($matrix['severityAxis']) * count($matrix['behaviourAxis']) * count($matrix['actorTypeAxis']);
		$this->assertCount(expectedCount: $expected, haystack: $matrix['cells']);
		$this->assertCount(expectedCount: $expected, haystack: $seen);
	}//end testTheMatrixIsDense()

	/**
	 * The resident's sixteen cells read as the board draws them, columns A to D.
	 *
	 * @return void
	 */
	public function testTheResidentRowsAreTheBoards(): void {
		$matrix = array_column($this->set()['objects'], null, 'key')['handhavingsmatrix-zuiddrecht-v2']['data'];
		$rows   = [];
		foreach ($matrix['cells'] as $cell) {
			if ($cell['actorType'] === 'burger') {
				$rows[$cell['behaviour']][] = $cell['intervention'];
			}
		}

		$this->assertSame(
			expected: [
				'goedwillend'   => ['herstelactie', 'herstelactie', 'warning', 'last_under_penaltypayment'],
				'onverschillig' => ['herstelactie', 'warning', 'last_under_penaltypayment', 'last_under_penaltypayment'],
				'calculerend'   => ['warning', 'last_under_penaltypayment', 'last_under_penaltypayment', 'pv_plus_bestuursdwang'],
				'crimineel'     => ['last_under_penaltypayment', 'pv_plus_bestuursdwang', 'pv_plus_bestuursdwang', 'pv_plus_bestuursdwang'],
			],
			actual: $rows
		);
	}//end testTheResidentRowsAreTheBoards()

	/**
	 * Both Molenweg advices were calculated on version 2, in the cell of their recommendation.
	 *
	 * @return void
	 */
	public function testTheAdvicesNameVersionTwo(): void {
		$objects = array_column($this->set()['objects'], null, 'key');
		$cells   = [];
		foreach ($objects['handhavingsmatrix-zuiddrecht-v2']['data']['cells'] as $cell) {
			$cells[$cell['severity'].'|'.$cell['behaviour'].'|'.$cell['actorType']] = $cell['intervention'];
		}

		foreach (['lhs-advies-molenweg', 'lhs-advies-molenweg-4'] as $key) {
			$advice = $objects[$key]['data'];
			$this->assertSame(expected: 2, actual: $advice['matrixVersion']);
			$this->assertSame(
				expected: $advice['recommendedIntervention'],
				actual: $cells[$advice['severity'].'|'.$advice['behaviour'].'|'.$advice['actorType']]
			);
		}
	}//end testTheAdvicesNameVersionTwo()
}//end class
