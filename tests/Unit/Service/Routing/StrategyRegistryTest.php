<?php

/**
 * Unit tests for StrategyRegistry: the five shipped strategies are registered
 * under their own names, a registered strategy replaces one of the same name,
 * and an unknown name is refused.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use OCA\Dossiq\Service\Routing\PoolMembership;
use OCA\Dossiq\Service\Routing\RoutingStrategyInterface;
use OCA\Dossiq\Service\Routing\RoutingStrategyMissingException;
use OCA\Dossiq\Service\Routing\Strategy\HierarchicalStrategy;
use OCA\Dossiq\Service\Routing\Strategy\LeastLoadedStrategy;
use OCA\Dossiq\Service\Routing\Strategy\OrSetStrategy;
use OCA\Dossiq\Service\Routing\Strategy\RoundRobinStrategy;
use OCA\Dossiq\Service\Routing\Strategy\SingleRoleStrategy;
use OCA\Dossiq\Service\Routing\StrategyRegistry;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Routing\StrategyRegistry
 *
 * @uses \OCA\Dossiq\Service\Routing\Strategy\SingleRoleStrategy
 * @uses \OCA\Dossiq\Service\Routing\Strategy\OrSetStrategy
 * @uses \OCA\Dossiq\Service\Routing\Strategy\HierarchicalStrategy
 * @uses \OCA\Dossiq\Service\Routing\Strategy\RoundRobinStrategy
 * @uses \OCA\Dossiq\Service\Routing\Strategy\LeastLoadedStrategy
 */
class StrategyRegistryTest extends TestCase {

	private function registry(): StrategyRegistry {
		$pool = new PoolMembership();
		return new StrategyRegistry(
			new SingleRoleStrategy(),
			new OrSetStrategy(),
			new HierarchicalStrategy(),
			new RoundRobinStrategy($this->createMock(IAppConfig::class), $pool),
			new LeastLoadedStrategy($pool)
		);
	}//end registry()

	public function testTheShippedStrategiesAreRegisteredByName(): void {
		$registry = $this->registry();

		$this->assertSame(['single-role', 'or-set', 'hierarchical', 'round-robin', 'least-loaded'], $registry->list());
		$this->assertTrue($registry->has('round-robin'));
		$this->assertInstanceOf(HierarchicalStrategy::class, $registry->get('hierarchical'));
		$this->assertInstanceOf(LeastLoadedStrategy::class, $registry->get('least-loaded'));
	}//end testTheShippedStrategiesAreRegisteredByName()

	public function testARegisteredStrategyIsAddedOrReplacesItsName(): void {
		$registry = $this->registry();
		$custom = $this->createMock(RoutingStrategyInterface::class);
		$custom->method('name')->willReturn('or-set');
		$extra = $this->createMock(RoutingStrategyInterface::class);
		$extra->method('name')->willReturn('by-area');

		$registry->register($custom);
		$registry->register($extra);

		$this->assertSame($custom, $registry->get('or-set'));
		$this->assertSame($extra, $registry->get('by-area'));
		$this->assertSame(['single-role', 'or-set', 'hierarchical', 'round-robin', 'least-loaded', 'by-area'], $registry->list());
	}//end testARegisteredStrategyIsAddedOrReplacesItsName()

	public function testAnUnknownStrategyIsRefused(): void {
		$registry = $this->registry();

		$this->assertFalse($registry->has('Round-Robin'));
		$this->expectException(RoutingStrategyMissingException::class);
		$this->expectExceptionMessage('Routing strategy "by-area" is not registered');

		$registry->get('by-area');
	}//end testAnUnknownStrategyIsRefused()
}//end class
