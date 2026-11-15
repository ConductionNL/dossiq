<?php

/**
 * OrSetStrategy and SingleRoleStrategy tests.
 *
 * Pins who each strategy routes to: every holder of any allowed role type,
 * once each in role order; and every holder of the one role type, the
 * earliest-created role first.
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
 * Tests for the role-set strategies.
 */
class RoleSetStrategiesTest extends TestCase {
	/**
	 * The roles on the case.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function roles(): array {
		return [
			['roleType' => 'jurist', 'participant' => 'ben', 'created' => '2026-03-02'],
			['roleType' => 'behandelaar', 'participant' => 'anouk', 'created' => '2026-03-03'],
			['roleType' => 'jurist', 'participant' => '', 'created' => '2026-01-01'],
			['roleType' => 'behandelaar', 'participant' => 'ben', '@self' => ['created' => '2026-03-01']],
			['roleType' => 'adviseur', 'participant' => 'cor'],
			['roleType' => 'jurist', 'participant' => 'dewi', 'created' => '2026-02-01'],
		];
	}//end roles()

	/**
	 * Every holder of an allowed type, once, in role order; nothing without types.
	 *
	 * @return void
	 */
	public function testTheOrSetRoutesToEveryAllowedHolderOnce(): void {
		$strategy = new OrSetStrategy();

		self::assertSame(['ben', 'anouk', 'dewi'], $strategy->resolve(['roleTypes' => ['jurist', '', 'behandelaar']], [], self::roles()));
		self::assertSame([], $strategy->resolve(['roleTypes' => ['']], [], self::roles()));
		self::assertSame([], $strategy->resolve(['roleTypes' => 'jurist'], [], self::roles()));
	}//end testTheOrSetRoutesToEveryAllowedHolderOnce()

	/**
	 * Every holder of the one type, the earliest-created role first; nothing without a type.
	 *
	 * @return void
	 */
	public function testTheSingleRoleRoutesByCreation(): void {
		$strategy = new SingleRoleStrategy();

		self::assertSame(['dewi', 'ben'], $strategy->resolve(['roleType' => 'jurist'], [], self::roles()));
		self::assertSame(['ben', 'anouk'], $strategy->resolve(['roleType' => 'behandelaar'], [], self::roles()));
		self::assertSame([], $strategy->resolve([], [], self::roles()));
	}//end testTheSingleRoleRoutesByCreation()
}//end class
