<?php

/**
 * The area rewriter is actually asked.
 *
 * `AreaRouting` shipped with REQ-RTP-04, has its own suite, and had NO CALLER:
 * `git grep` outside its test file found nothing. Its own suite passed
 * throughout, because a pure class answers the same whether anybody asks it or
 * not. That is the shape this file exists to stop coming back, so it asserts
 * the thing a unit test of the rewriter never could: that a rule reaching a
 * strategy has been through it.
 *
 * THE STRATEGY IS THE WITNESS. The rule the strategy receives is captured and
 * asserted, rather than the resolver's return value, because the return is a
 * list of user ids and a rewrite that never happened produces exactly the same
 * list whenever the fixture's roles do not depend on the team.
 *
 * MUTATION-CHECKED 2026-09-18: dropping the `$rule = $this->withArea(...)` line
 * from RoleResolverService::resolve() reddens
 * testTheAreaMapDecidesTheTeamTheStrategySees and
 * testATeamOnTheCaseWinsOverTheRulesOwn. Restored after.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Routing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use OCA\Dossiq\Service\RoleResolverService;
use OCA\Dossiq\Service\Routing\AreaRouting;
use OCA\Dossiq\Service\Routing\RoleDelegationResolver;
use OCA\Dossiq\Service\Routing\RoleResolutionCache;
use OCA\Dossiq\Service\Routing\RoutingStrategyInterface;
use OCA\Dossiq\Service\Routing\StrategyRegistry;
use OCA\Dossiq\Service\SettingsService;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A rule reaching a strategy has been through the area rewriter.
 *
 * @covers \OCA\Dossiq\Service\RoleResolverService::resolve
 * @uses \OCA\Dossiq\Service\Routing\AreaRouting
 * @uses \OCA\Dossiq\Service\Routing\RoleDelegationResolver
 * @uses \OCA\Dossiq\Service\Routing\RoleResolutionCache
 * @uses \OCA\Dossiq\Service\Routing\RoutingStrategyInterface
 * @uses \OCA\Dossiq\Service\Routing\StrategyRegistry
 * @uses \OCA\Dossiq\Service\SettingsService
 * @uses \OCA\Dossiq\Service\RoleResolverService
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */
class AreaRoutingIsConsultedTest extends TestCase {

	/**
	 * The rule the strategy was handed, captured on the way past.
	 *
	 * @var array<string, mixed>
	 */
	private array $seen = [];

	/**
	 * A rule whose area map names the team for wijk Zuid.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md#requirement-the-case-holds-the-area-it-is-in-and-routing-reads-it-req-rtp-04
	 */
	public function testTheAreaMapDecidesTheTeamTheStrategySees(): void {
		$this->resolver()->resolve(
			rule: [
				'strategy' => 'single-role',
				'roleType' => 'behandelaar',
				'team' => 'algemeen',
				'areaTeams' => ['Zuid' => 'wijkteam-zuid', 'Noord' => 'wijkteam-noord'],
			],
			case: ['id' => 'case-1', 'district' => 'Zuid'],
		);

		self::assertSame(
			'wijkteam-zuid',
			$this->seen['team'],
			'A rule carrying an area map routed as though the map were not there, and its own suite still passed.',
		);
	}//end testTheAreaMapDecidesTheTeamTheStrategySees()

	/**
	 * A team written on the case beats the rule's own.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md#requirement-the-case-holds-the-area-it-is-in-and-routing-reads-it-req-rtp-04
	 */
	public function testATeamOnTheCaseWinsOverTheRulesOwn(): void {
		$this->resolver()->resolve(
			rule: ['strategy' => 'single-role', 'roleType' => 'behandelaar', 'team' => 'algemeen'],
			case: ['id' => 'case-1', 'districtTeam' => 'wijkteam-oost'],
		);

		self::assertSame(
			'wijkteam-oost',
			$this->seen['team'],
			'`districtTeam` is where a gemeente records that THIS case belongs to THAT area team.',
		);
	}//end testATeamOnTheCaseWinsOverTheRulesOwn()

	/**
	 * A rule written before REQ-RTP-04 is handed on unchanged.
	 *
	 * The control. Without it, a rewrite that overwrote every rule's team with
	 * an empty string would pass both tests above.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md#requirement-the-case-holds-the-area-it-is-in-and-routing-reads-it-req-rtp-04
	 */
	public function testAnOrdinaryRuleIsUnchanged(): void {
		$rule = ['strategy' => 'single-role', 'roleType' => 'behandelaar', 'team' => 'algemeen'];

		$this->resolver()->resolve(rule: $rule, case: ['id' => 'case-1']);

		self::assertSame('algemeen', $this->seen['team'], 'No area map and no team on the case is a no-op.');
		self::assertSame('behandelaar', $this->seen['roleType']);
	}//end testAnOrdinaryRuleIsUnchanged()

	/**
	 * A resolver whose single strategy records the rule it is handed.
	 *
	 * @return RoleResolverService The subject.
	 */
	private function resolver(): RoleResolverService {
		$strategy = new class($this) implements RoutingStrategyInterface {

			/**
			 * Constructor.
			 *
			 * @param AreaRoutingIsConsultedTest $test The test to report to.
			 */
			public function __construct(private readonly AreaRoutingIsConsultedTest $test) {
			}

			/**
			 * The strategy's name.
			 *
			 * @return string The name.
			 */
			public function name(): string {
				return 'single-role';
			}

			/**
			 * Record the rule and resolve to nobody.
			 *
			 * @param array<string, mixed> $rule  The rule.
			 * @param array<string, mixed> $case  The case.
			 * @param array<int, array<string, mixed>> $roles The case's roles.
			 *
			 * @return array<int, string> The participants.
			 */
			public function resolve(array $rule, array $case, array $roles): array {
				$this->test->record(rule: $rule);

				return [];
			}
		};

		$registry = $this->createMock(originalClassName: StrategyRegistry::class);
		$registry->method('has')->willReturn(true);
		$registry->method('get')->willReturn($strategy);

		$delegation = $this->createMock(originalClassName: RoleDelegationResolver::class);
		$delegation->method('apply')->willReturn([]);

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);
		$settings->method('getConfigValue')->willReturn('');

		$cache = $this->createMock(originalClassName: ICache::class);
		$cache->method('get')->willReturn(null);
		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('createLocal')->willReturn($cache);

		return new RoleResolverService(
			registry: $registry,
			settingsService: $settings,
			// A REAL cache over the SAME factory double, which answers a miss,
			// so every resolve in this suite still runs the strategy. Only the
			// wiring line moved when the cache was split out.
			cache: new RoleResolutionCache($cacheFactory),
			delegation: $delegation,
			area: new AreaRouting(),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end resolver()

	/**
	 * Take note of the rule a strategy was handed.
	 *
	 * @param array<string, mixed> $rule The rule.
	 *
	 * @return void
	 */
	public function record(array $rule): void {
		$this->seen = $rule;
	}//end record()
}//end class
