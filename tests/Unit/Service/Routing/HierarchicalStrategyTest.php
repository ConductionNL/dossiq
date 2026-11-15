<?php

/**
 * Unit tests for HierarchicalStrategy: a case routes to every holder of the
 * first role type in priority order that anyone holds, then to the fallback
 * type, and to nobody when none is held.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use OCA\Dossiq\Service\Routing\Strategy\HierarchicalStrategy;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Routing\Strategy\HierarchicalStrategy
 */
class HierarchicalStrategyTest extends TestCase {

	/**
	 * @return list<array<string, string>>
	 */
	private function roles(): array {
		return [
			['roleType' => 'behandelaar', 'participant' => 'alice'],
			['roleType' => 'teamleider', 'participant' => 'bob'],
			['roleType' => 'behandelaar', 'participant' => 'carol'],
			['roleType' => 'behandelaar', 'participant' => ''],
			['roleType' => 'manager', 'participant' => 'dave'],
		];
	}//end roles()

	public function testTheFirstHeldTypeInPriorityOrderWins(): void {
		$strategy = new HierarchicalStrategy();

		$this->assertSame('hierarchical', $strategy->name());
		$this->assertSame(['alice', 'carol'], $strategy->resolve(['roleTypes' => ['behandelaar', 'teamleider']], [], $this->roles()));
		$this->assertSame(['bob'], $strategy->resolve(['roleTypes' => ['specialist', '', 'teamleider', 'behandelaar']], [], $this->roles()));
	}//end testTheFirstHeldTypeInPriorityOrderWins()

	public function testTheFallbackTypeComesLast(): void {
		$strategy = new HierarchicalStrategy();

		$this->assertSame(['dave'], $strategy->resolve(['roleTypes' => ['specialist'], 'fallback' => 'manager'], [], $this->roles()));
		$this->assertSame(['alice', 'carol'], $strategy->resolve(['roleTypes' => ['behandelaar'], 'fallback' => 'manager'], [], $this->roles()));
		$this->assertSame(['dave'], $strategy->resolve(['roleTypes' => 'behandelaar', 'fallback' => 'manager'], [], $this->roles()));
	}//end testTheFallbackTypeComesLast()

	public function testNobodyIsRoutedWhenNoTypeIsHeld(): void {
		$strategy = new HierarchicalStrategy();

		$this->assertSame([], $strategy->resolve(['roleTypes' => ['specialist'], 'fallback' => 'jurist'], [], $this->roles()));
		$this->assertSame([], $strategy->resolve([], [], $this->roles()));
		$this->assertSame([], $strategy->resolve(['roleTypes' => ['behandelaar']], [], []));
	}//end testNobodyIsRoutedWhenNoTypeIsHeld()
}//end class
