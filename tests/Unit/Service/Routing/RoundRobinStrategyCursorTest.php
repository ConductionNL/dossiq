<?php

/**
 * RoundRobinStrategy cursor tests.
 *
 * Pins where the rotation cursor is stored, how it advances and wraps, and
 * that a rule that cannot route reads and writes nothing.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use OCA\Dossiq\Service\Routing\PoolMembership;
use OCA\Dossiq\Service\Routing\Strategy\RoundRobinStrategy;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the round-robin cursor.
 *
 * @covers \OCA\Dossiq\Service\Routing\Strategy\RoundRobinStrategy
 *
 * @uses \OCA\Dossiq\Service\Routing\PoolMembership
 */
class RoundRobinStrategyCursorTest extends TestCase {

	/**
	 * The app config double.
	 *
	 * @var IAppConfig&MockObject
	 */
	private IAppConfig $config;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->config = $this->createMock(IAppConfig::class);
	}//end setUp()

	/**
	 * The roles on the case: three handlers, two of them in team zuid.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function roles(): array {
		return [
			['roleType' => 'behandelaar', 'participant' => 'anouk', 'team' => 'zuid'],
			['roleType' => 'behandelaar', 'participant' => 'ben'],
			['roleType' => 'behandelaar', 'participant' => 'cor', 'team' => 'zuid'],
			['roleType' => 'jurist', 'participant' => 'dewi'],
		];
	}//end roles()

	/**
	 * The strategy under test.
	 *
	 * @return RoundRobinStrategy
	 */
	private function strategy(): RoundRobinStrategy {
		return new RoundRobinStrategy($this->config, new PoolMembership());
	}//end strategy()

	/**
	 * The strategy registers as round-robin.
	 *
	 * @return void
	 */
	public function testTheStrategyIsNamedRoundRobin(): void {
		self::assertSame('round-robin', $this->strategy()->name());
	}//end testTheStrategyIsNamedRoundRobin()

	/**
	 * The cursor is read and advanced under the case type and role type.
	 *
	 * @return void
	 */
	public function testTheCursorIsKeyedByCaseTypeAndRoleType(): void {
		$this->config->expects(self::once())->method('getValueInt')
			->with('dossiq', 'routing.rr.ct-permit.behandelaar', 0)->willReturn(1);
		$this->config->expects(self::once())->method('setValueInt')
			->with('dossiq', 'routing.rr.ct-permit.behandelaar', 2)->willReturn(true);

		self::assertSame(['ben'], $this->strategy()->resolve(['roleType' => 'behandelaar'], ['caseType' => 'ct-permit'], self::roles()));
	}//end testTheCursorIsKeyedByCaseTypeAndRoleType()

	/**
	 * A team-narrowed rule keeps its own cursor and rotates only within the team.
	 *
	 * @return void
	 */
	public function testATeamRuleHasItsOwnCursor(): void {
		$this->config->expects(self::once())->method('getValueInt')
			->with('dossiq', 'routing.rr.ct-permit.behandelaar.zuid', 0)->willReturn(1);
		$this->config->expects(self::once())->method('setValueInt')
			->with('dossiq', 'routing.rr.ct-permit.behandelaar.zuid', 0)->willReturn(true);

		self::assertSame(
			['cor'],
			$this->strategy()->resolve(['roleType' => 'behandelaar', 'team' => ' zuid '], ['caseType' => 'ct-permit'], self::roles())
		);
	}//end testATeamRuleHasItsOwnCursor()

	/**
	 * A stored cursor past the end of a shrunken pool wraps instead of failing.
	 *
	 * @return void
	 */
	public function testACursorPastThePoolWraps(): void {
		$this->config->method('getValueInt')->willReturn(7);
		$this->config->expects(self::once())->method('setValueInt')
			->with('dossiq', 'routing.rr..behandelaar', 2)->willReturn(true);

		self::assertSame(['ben'], $this->strategy()->resolve(['roleType' => 'behandelaar'], [], self::roles()));
	}//end testACursorPastThePoolWraps()

	/**
	 * A rule without a role type, or a pool without members, touches no cursor.
	 *
	 * @return void
	 */
	public function testARuleThatCannotRouteTouchesNoCursor(): void {
		$this->config->expects(self::never())->method('getValueInt');
		$this->config->expects(self::never())->method('setValueInt');

		$strategy = $this->strategy();
		self::assertSame([], $strategy->resolve([], ['caseType' => 'ct-permit'], self::roles()));
		self::assertSame([], $strategy->resolve(['roleType' => 'adviseur'], ['caseType' => 'ct-permit'], self::roles()));
		self::assertSame([], $strategy->resolve(['roleType' => 'behandelaar', 'team' => 'noord'], ['caseType' => 'ct-permit'], self::roles()));
	}//end testARuleThatCannotRouteTouchesNoCursor()
}//end class
