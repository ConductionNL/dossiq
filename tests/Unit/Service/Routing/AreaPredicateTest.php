<?php

/**
 * The area team handles the area, and says when it could not.
 *
 * 🔴 THE FALLBACK MUST BE VISIBLE. A case whose address placed in no boundary
 * and was routed by the fallback looks identical, on the case, to one the area
 * rule placed correctly. `fallbackUsed` is the only difference, so it is
 * asserted on every path that can produce it, and asserted ABSENT on the paths
 * that must not.
 *
 * 🔴 `districtTeam` HAS BEEN DECLARED WITH NO READER. A test that only
 * exercised the rule's own map would leave it exactly as it was: a
 * configuration field an administrator fills in and watches do nothing.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Routing
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use OCA\Dossiq\Service\Routing\AreaRouting;
use PHPUnit\Framework\TestCase;

/**
 * The area predicate.
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */
class AreaPredicateTest extends TestCase {
	private AreaRouting $areas;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->areas = new AreaRouting();
	}//end setUp()

	/**
	 * A rule over wijken.
	 *
	 * @return array<string, mixed> The rule.
	 */
	private function areaRule(): array {
		return [
			'roleType' => 'behandelaar',
			'areaTeams' => ['Zuid' => 'team-zuid', 'Noord' => 'team-noord'],
		];
	}//end areaRule()

	/**
	 * The case in wijk Zuid reaches the team declared for Zuid.
	 *
	 * @return void
	 */
	public function testTheAreaTeamGetsTheCase(): void {
		$resolved = $this->areas->resolve($this->areaRule(), ['district' => 'Zuid']);

		$this->assertSame('team-zuid', $resolved['team']);
		$this->assertFalse($resolved['fallbackUsed']);
		$this->assertSame('behandelaar', $resolved['roleType']);
	}//end testTheAreaTeamGetsTheCase()

	/**
	 * A boundary set writes Zuid and an administrator types zuid.
	 *
	 * @return void
	 */
	public function testTheMatchIsNotDefeatedByACapital(): void {
		$resolved = $this->areas->resolve($this->areaRule(), ['district' => 'zuid']);

		$this->assertSame('team-zuid', $resolved['team']);
		$this->assertFalse($resolved['fallbackUsed']);
	}//end testTheMatchIsNotDefeatedByACapital()

	/**
	 * A buurt rule reaches its team when the wijk names none.
	 *
	 * @return void
	 */
	public function testANeighbourhoodIsReadWhenTheDistrictDecidesNothing(): void {
		$rule = ['roleType' => 'behandelaar', 'areaTeams' => ['Binnenstad' => 'team-centrum']];

		$resolved = $this->areas->resolve($rule, ['district' => 'West', 'neighbourhood' => 'Binnenstad']);

		$this->assertSame('team-centrum', $resolved['team']);
	}//end testANeighbourhoodIsReadWhenTheDistrictDecidesNothing()

	/**
	 * `districtTeam` on the case wins, and is finally read by something.
	 *
	 * @return void
	 */
	public function testDistrictTeamOnTheCaseWinsOverTheRulesMap(): void {
		$resolved = $this->areas->resolve(
			$this->areaRule(),
			['district' => 'Zuid', 'districtTeam' => 'wijkteam-4'],
		);

		$this->assertSame('wijkteam-4', $resolved['team']);
		$this->assertFalse($resolved['fallbackUsed']);
	}//end testDistrictTeamOnTheCaseWinsOverTheRulesMap()

	/**
	 * A case outside every boundary routes by the case type's fallback, and
	 * says that it did.
	 *
	 * @return void
	 */
	public function testAnUnplacedCaseUsesTheDeclaredFallbackAndSaysSo(): void {
		$resolved = $this->areas->resolve(
			$this->areaRule(),
			['district' => '', 'neighbourhood' => ''],
			['areaFallbackRoleType' => 'centrale-balie'],
		);

		$this->assertTrue($resolved['fallbackUsed']);
		$this->assertSame('centrale-balie', $resolved['roleType']);
		$this->assertSame('', $resolved['team']);
		$this->assertStringContainsString('no area', $resolved['reason']);
	}//end testAnUnplacedCaseUsesTheDeclaredFallbackAndSaysSo()

	/**
	 * An area nobody declared a team for is a fallback too, and the reason
	 * names the area so an administrator can fix the map.
	 *
	 * @return void
	 */
	public function testAnAreaWithNoTeamNamesItselfInTheReason(): void {
		$resolved = $this->areas->resolve($this->areaRule(), ['district' => 'Oost']);

		$this->assertTrue($resolved['fallbackUsed']);
		$this->assertStringContainsString('Oost', $resolved['reason']);
	}//end testAnAreaWithNoTeamNamesItselfInTheReason()

	/**
	 * A case type declaring no fallback still routes: the work reaches the
	 * pool it always would have, and the flag says the area did not decide it.
	 *
	 * @return void
	 */
	public function testWithoutADeclaredFallbackTheRulesOwnRoleIsKept(): void {
		$resolved = $this->areas->resolve($this->areaRule(), ['district' => 'Oost'], []);

		$this->assertTrue($resolved['fallbackUsed']);
		$this->assertSame('behandelaar', $resolved['roleType']);
	}//end testWithoutADeclaredFallbackTheRulesOwnRoleIsKept()

	/**
	 * An ordinary rule that says nothing about area is not marked as having
	 * fallen back, which would otherwise flag every case in the gemeente.
	 *
	 * @return void
	 */
	public function testARuleWithNoAreaMapIsNotAFallback(): void {
		$resolved = $this->areas->resolve(['roleType' => 'behandelaar', 'team' => 'zuid'], []);

		$this->assertFalse($resolved['fallbackUsed']);
		$this->assertSame('zuid', $resolved['team']);
		$this->assertSame('', $resolved['reason']);
	}//end testARuleWithNoAreaMapIsNotAFallback()
}//end class
