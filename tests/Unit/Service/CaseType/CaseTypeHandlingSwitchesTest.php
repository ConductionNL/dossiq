<?php

/**
 * Unit tests for CaseTypeHandling: declared switches win, and a switch nothing reads is named.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseTypeHandling;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the declared handling switches.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseTypeHandling
 */
class CaseTypeHandlingSwitchesTest extends TestCase {

	/**
	 * A declared default group wins over the legacy intake destinations.
	 *
	 * @return void
	 */
	public function testADeclaredDefaultGroupWinsOverTheLegacyDestination(): void {
		$caseType = [
			CaseTypeHandling::PROPERTY => ['defaultGroup' => 'vergunningen'],
			'intakeDestinations' => [['department' => 'legacy']],
		];

		$this->assertSame(expected: 'vergunningen', actual: (new CaseTypeHandling())->defaultGroup(caseType: $caseType));
		$this->assertSame(expected: ['vergunningen'], actual: (new CaseTypeHandling())->teams(caseType: $caseType));
	}//end testADeclaredDefaultGroupWinsOverTheLegacyDestination()

	/**
	 * A declared intake screen is returned as declared.
	 *
	 * @return void
	 */
	public function testADeclaredIntakeScreenIsReturned(): void {
		$caseType = [CaseTypeHandling::PROPERTY => ['intakeScreen' => 'short-form']];

		$this->assertSame(expected: 'short-form', actual: (new CaseTypeHandling())->intakeScreen(caseType: $caseType));
		$this->assertSame(expected: 'short-form', actual: (new CaseTypeHandling())->block(caseType: $caseType)['intakeScreen']);
	}//end testADeclaredIntakeScreenIsReturned()

	/**
	 * Switches nothing reads are named, sorted, as strings; read switches are not.
	 *
	 * @return void
	 */
	public function testUnreadSwitchesAreNamedSorted(): void {
		$caseType = [
			CaseTypeHandling::PROPERTY => [
				'teams' => ['a'],
				'zeta' => true,
				'defaultGroup' => 'g',
				'alpha' => 1,
				7 => 'numbered',
			],
		];

		$this->assertSame(expected: ['7', 'alpha', 'zeta'], actual: (new CaseTypeHandling())->unreadSwitches(caseType: $caseType));
	}//end testUnreadSwitchesAreNamedSorted()

	/**
	 * A case type without a block, or with a block of another shape, has no unread switches.
	 *
	 * @return void
	 */
	public function testNoBlockHasNoUnreadSwitches(): void {
		$handling = new CaseTypeHandling();

		$this->assertSame(expected: [], actual: $handling->unreadSwitches(caseType: []));
		$this->assertSame(expected: [], actual: $handling->unreadSwitches(caseType: [CaseTypeHandling::PROPERTY => 'defaultGroup']));
		$this->assertSame(
			expected: [],
			actual: $handling->unreadSwitches(caseType: [CaseTypeHandling::PROPERTY => array_fill_keys(CaseTypeHandling::READ_SWITCHES, '')])
		);
	}//end testNoBlockHasNoUnreadSwitches()
}//end class
