<?php

/**
 * Example data for DqHandhaving: an LHS recommendation on Molenweg 4, made
 * by Lars de Koning against the shipped matrix, advising a penalty
 * order for a serious violation.
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
 * @spec openspec/changes/example-set-lhs-molenweg-4/specs/example-set-lhs-molenweg-4/spec.md#requirement-molenweg-4-has-its-lhs-recommendation
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * The LHS recommendation the enforcement board draws.
 *
 * @spec openspec/changes/example-set-lhs-molenweg-4/specs/example-set-lhs-molenweg-4/spec.md#requirement-molenweg-4-has-its-lhs-recommendation
 */
class ZuiddrechtLhsMolenweg4Test extends TestCase {

	/**
	 * On the Molenweg 4 case, by Lars, the shipped matrix, serious, a penalty order.
	 *
	 * @return void
	 */
	public function testMolenweg4HasItsRecommendation(): void {
		$set    = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$advice = array_column($set['objects'], null, 'key')['lhs-advies-molenweg-4']['data'];

		$this->assertSame(expected: '{{object:kapvergunning-molenweg.id}}', actual: $advice['case']);
		$this->assertSame(expected: '{{cast:lars.uid}}', actual: $advice['recommendedBy']);
		$this->assertSame(expected: [1, 'ernstig', 'last_under_penaltypayment'], actual: [$advice['matrixVersion'], $advice['severity'], $advice['recommendedIntervention']]);
	}//end testMolenweg4HasItsRecommendation()

	/**
	 * The board's cell is behaviour 2 by consequence C, and Anouk, as team
	 * lead, overrules the advice with a lighter warning letter and a reason.
	 *
	 * @return void
	 */
	public function testMolenweg4IsOverruledWithAWarning(): void {
		$set    = json_decode((string)file_get_contents(__DIR__.'/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		$advice = array_column($set['objects'], null, 'key')['lhs-advies-molenweg-4']['data'];

		$this->assertSame(expected: 'onverschillig', actual: $advice['behaviour']);
		$this->assertSame(
			expected: [true, 'warning', 'manager', '{{cast:anouk.uid}}'],
			actual: [$advice['override'], $advice['finalIntervention'], $advice['overrideAuthority'], $advice['overrideBy']]
		);
		$this->assertNotSame(expected: '', actual: $advice['overrideJustification']);
	}//end testMolenweg4IsOverruledWithAWarning()
}//end class
