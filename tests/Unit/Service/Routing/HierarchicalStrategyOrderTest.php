<?php

/**
 * HierarchicalStrategy tests.
 *
 * Pins the fall-through: the first role type in priority order that has a
 * holder wins, the fallback type is tried last, and nothing matches when no
 * listed type has a holder.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use OCA\Dossiq\Service\Routing\Strategy\HierarchicalStrategy;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the hierarchical fall-through strategy.
 *
 * @covers \OCA\Dossiq\Service\Routing\Strategy\HierarchicalStrategy
 */
class HierarchicalStrategyOrderTest extends TestCase {
	/**
	 * The roles on the case.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function roles(): array {
		return [
			['roleType' => 'behandelaar', 'participant' => 'anouk'],
			['roleType' => 'jurist', 'participant' => ''],
			['roleType' => 'teamleider', 'participant' => 'ben'],
			['roleType' => 'behandelaar', 'participant' => 'cor'],
			['participant' => 'dewi'],
			['roleType' => 'teamleider', 'participant' => 'erik'],
		];
	}//end roles()

	/**
	 * The strategy is registered under its own name.
	 *
	 * @return void
	 */
	public function testItIsNamedHierarchical(): void {
		self::assertSame('hierarchical', (new HierarchicalStrategy())->name());
	}//end testItIsNamedHierarchical()

	/**
	 * The first listed type with holders wins, with every holder of it in role order.
	 *
	 * @return void
	 */
	public function testTheFirstTypeWithHoldersWins(): void {
		$strategy = new HierarchicalStrategy();

		self::assertSame(['ben', 'erik'], $strategy->resolve(['roleTypes' => ['teamleider', 'behandelaar']], [], self::roles()));
		self::assertSame(['anouk', 'cor'], $strategy->resolve(['roleTypes' => ['behandelaar', 'teamleider']], [], self::roles()));
	}//end testTheFirstTypeWithHoldersWins()

	/**
	 * A type whose only holder has no participant counts as empty, and the next type is tried.
	 *
	 * @return void
	 */
	public function testATypeWithoutParticipantsFallsThrough(): void {
		$strategy = new HierarchicalStrategy();

		self::assertSame(['ben', 'erik'], $strategy->resolve(['roleTypes' => ['jurist', 'adviseur', 'teamleider']], [], self::roles()));
	}//end testATypeWithoutParticipantsFallsThrough()

	/**
	 * The fallback is tried only after every listed type is empty.
	 *
	 * @return void
	 */
	public function testTheFallbackIsTriedLast(): void {
		$strategy = new HierarchicalStrategy();

		self::assertSame(['anouk', 'cor'], $strategy->resolve(['roleTypes' => ['jurist'], 'fallback' => 'behandelaar'], [], self::roles()));
		self::assertSame(['ben', 'erik'], $strategy->resolve(['roleTypes' => ['teamleider'], 'fallback' => 'behandelaar'], [], self::roles()));
		self::assertSame(['anouk', 'cor'], $strategy->resolve(['fallback' => 'behandelaar'], [], self::roles()));
	}//end testTheFallbackIsTriedLast()

	/**
	 * Blank and non-list role types are ignored, leaving only the fallback.
	 *
	 * @return void
	 */
	public function testBlankOrNonListTypesAreIgnored(): void {
		$strategy = new HierarchicalStrategy();

		self::assertSame(['ben', 'erik'], $strategy->resolve(['roleTypes' => ['', 'teamleider']], [], self::roles()));
		self::assertSame(['anouk', 'cor'], $strategy->resolve(['roleTypes' => 'teamleider', 'fallback' => 'behandelaar'], [], self::roles()));
		self::assertSame([], $strategy->resolve(['roleTypes' => 'teamleider'], [], self::roles()));
	}//end testBlankOrNonListTypesAreIgnored()

	/**
	 * Nothing matches when no listed type or fallback has a holder.
	 *
	 * @return void
	 */
	public function testNothingMatchesWithoutHolders(): void {
		$strategy = new HierarchicalStrategy();

		self::assertSame([], $strategy->resolve(['roleTypes' => ['jurist'], 'fallback' => 'adviseur'], [], self::roles()));
		self::assertSame([], $strategy->resolve([], [], self::roles()));
		self::assertSame([], $strategy->resolve(['roleTypes' => ['teamleider']], [], []));
	}//end testNothingMatchesWithoutHolders()
}//end class
