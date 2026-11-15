<?php

/**
 * OrSetStrategy and SingleRoleStrategy edge-case tests.
 *
 * Pins the names the strategies register under, the order SingleRoleStrategy
 * keeps for roles without a creation moment, and how OrSetStrategy reads
 * non-string role types.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use OCA\Dossiq\Service\Routing\Strategy\OrSetStrategy;
use OCA\Dossiq\Service\Routing\Strategy\SingleRoleStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Edge cases of the role-set strategies.
 *
 * @covers \OCA\Dossiq\Service\Routing\Strategy\OrSetStrategy
 * @covers \OCA\Dossiq\Service\Routing\Strategy\SingleRoleStrategy
 */
class RoleSetStrategiesEdgeCasesTest extends TestCase {
	/**
	 * Each strategy is registered under its own name.
	 *
	 * @return void
	 */
	public function testTheStrategiesAreNamed(): void {
		self::assertSame('or-set', (new OrSetStrategy())->name());
		self::assertSame('single-role', (new SingleRoleStrategy())->name());
	}//end testTheStrategiesAreNamed()

	/**
	 * Roles without a creation moment sort before dated ones and keep their input order.
	 *
	 * @return void
	 */
	public function testUndatedRolesKeepTheirOrderAheadOfDatedOnes(): void {
		$roles = [
			['roleType' => 'jurist', 'participant' => 'ben', 'created' => '2026-01-01'],
			['roleType' => 'jurist', 'participant' => 'cor'],
			['roleType' => 'jurist', 'participant' => 'anouk'],
			['roleType' => 'jurist', 'participant' => 'dewi', '@self' => ['created' => '2025-12-31']],
		];

		self::assertSame(['cor', 'anouk', 'dewi', 'ben'], (new SingleRoleStrategy())->resolve(['roleType' => 'jurist'], [], $roles));
	}//end testUndatedRolesKeepTheirOrderAheadOfDatedOnes()

	/**
	 * A role type that nobody holds, or held without a participant, routes to nobody.
	 *
	 * @return void
	 */
	public function testSingleRoleWithoutHoldersRoutesToNobody(): void {
		$roles = [['roleType' => 'jurist', 'participant' => ''], ['participant' => 'ben']];

		self::assertSame([], (new SingleRoleStrategy())->resolve(['roleType' => 'jurist'], [], $roles));
		self::assertSame([], (new SingleRoleStrategy())->resolve(['roleType' => 'adviseur'], [], $roles));
	}//end testSingleRoleWithoutHoldersRoutesToNobody()

	/**
	 * The same participant under two allowed types is routed once, at its first role.
	 *
	 * @return void
	 */
	public function testOrSetCollapsesAParticipantAcrossTypes(): void {
		$roles = [
			['roleType' => 'adviseur', 'participant' => 'cor'],
			['roleType' => 'jurist', 'participant' => 'ben'],
			['roleType' => 'adviseur', 'participant' => 'ben'],
			['roleType' => 'teamleider', 'participant' => 'dewi'],
			['participant' => 'erik'],
		];

		self::assertSame(['cor', 'ben'], (new OrSetStrategy())->resolve(['roleTypes' => ['jurist', 'adviseur']], [], $roles));
	}//end testOrSetCollapsesAParticipantAcrossTypes()

	/**
	 * Role types are compared as strings, so a numeric type matches its string form.
	 *
	 * @return void
	 */
	public function testOrSetReadsRoleTypesAsStrings(): void {
		$roles = [['roleType' => '7', 'participant' => 'ben'], ['roleType' => 7, 'participant' => 'cor']];

		self::assertSame(['ben', 'cor'], (new OrSetStrategy())->resolve(['roleTypes' => [7]], [], $roles));
		self::assertSame([], (new OrSetStrategy())->resolve(['roleTypes' => []], [], $roles));
	}//end testOrSetReadsRoleTypesAsStrings()
}//end class
