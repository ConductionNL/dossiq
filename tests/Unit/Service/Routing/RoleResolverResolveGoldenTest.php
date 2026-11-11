<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use OCA\Dossiq\Service\RoleResolverService;
use OCA\Dossiq\Service\Routing\AreaRouting;
use OCA\Dossiq\Service\Routing\RoleDelegationResolver;
use OCA\Dossiq\Service\Routing\RoleResolutionCache;
use OCA\Dossiq\Service\Routing\RoutingStrategyInterface;
use OCA\Dossiq\Service\Routing\RoutingStrategyMissingException;
use OCA\Dossiq\Service\Routing\StrategyRegistry;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Who `resolve` routes to, who it refuses, and what it remembers and logs, in order.
 */
class RoleResolverResolveGoldenTest extends TestCase {

	/**
	 * Everything the collaborators were asked, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	public array $calls = [];

	/**
	 * What each strategy answers, by name.
	 *
	 * @var array<string, array<int, string>>
	 */
	private array $answers = [];

	/**
	 * What the cache holds; null for a miss.
	 *
	 * @var array<int, string>|null
	 */
	private ?array $remembered = null;

	/**
	 * A resolver over recording doubles.
	 *
	 * @return RoleResolverService The resolver.
	 */
	private function resolver(): RoleResolverService {
		$registry = $this->createMock(StrategyRegistry::class);
		$registry->method('has')->willReturnCallback(fn (string $name): bool => isset($this->answers[$name]));
		$registry->method('get')->willReturnCallback(fn (string $name): RoutingStrategyInterface => $this->strategy(name: $name));

		$cache = $this->createMock(RoleResolutionCache::class);
		$cache->method('get')->willReturnCallback(
			function (array $rule, string $caseId): ?array {
				$this->calls[] = ['cache.get', $rule, $caseId];

				return $this->remembered;
			}
		);
		$cache->method('remember')->willReturnCallback(
			function (array $rule, string $caseId, array $members): void {
				$this->calls[] = ['cache.remember', $rule, $caseId, $members];
			}
		);

		$delegation = $this->createMock(RoleDelegationResolver::class);
		$delegation->method('apply')->willReturnCallback(
			function (array $participants, array $roles): array {
				$this->calls[] = ['delegation', $participants, $roles];

				return array_map(static fn (string $who): string => $who . '+d', $participants);
			}
		);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn(null);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context): void {
				$this->calls[] = ['log.info', $message, $context];
			}
		);

		return new RoleResolverService(
			registry: $registry,
			settingsService: $settings,
			cache: $cache,
			delegation: $delegation,
			area: new AreaRouting(),
			logger: $logger,
		);
	}//end resolver()

	/**
	 * A strategy answering from `$this->answers`, recording what it was handed.
	 *
	 * @param string $name The strategy name.
	 *
	 * @return RoutingStrategyInterface The strategy.
	 */
	private function strategy(string $name): RoutingStrategyInterface {
		$test = $this;
		$answer = $this->answers[$name];

		return new class ($test, $name, $answer) implements RoutingStrategyInterface {
			/**
			 * @param RoleResolverResolveGoldenTest $test   The test.
			 * @param string                        $name   The name.
			 * @param array<int, string>            $answer The answer.
			 */
			public function __construct(private RoleResolverResolveGoldenTest $test, private string $name, private array $answer) {
			}

			/**
			 * @return string The name.
			 */
			public function name(): string {
				return $this->name;
			}

			/**
			 * @param array<string, mixed> $rule  The rule.
			 * @param array<string, mixed> $case  The case.
			 * @param array<int, mixed>    $roles The roles.
			 *
			 * @return array<int, string> The members.
			 */
			public function resolve(array $rule, array $case, array $roles): array {
				$this->test->calls[] = ['strategy.' . $this->name, $rule, $roles];

				return $this->answer;
			}
		};
	}//end strategy()

	/**
	 * Allowed: the strategy's members, delegated, remembered under the area-rewritten rule.
	 *
	 * @return void
	 */
	public function testTheStrategysMembersAreDelegatedAndRemembered(): void {
		$this->answers = ['round-robin' => ['anna', 'bert']];
		$rule = ['strategy' => 'round-robin', 'roleType' => 'behandelaar'];

		$members = $this->resolver()->resolve(rule: $rule, case: ['uuid' => 'case-1', 'districtTeam' => 'oost']);

		$areaRule = $rule + ['team' => 'oost'];
		self::assertSame(['anna+d', 'bert+d'], $members);
		self::assertSame(
			[
				['cache.get', $rule, 'case-1'],
				['strategy.round-robin', $areaRule, []],
				['delegation', ['anna', 'bert'], []],
				['cache.remember', $areaRule, 'case-1', ['anna+d', 'bert+d']],
			],
			$this->calls
		);
	}//end testTheStrategysMembersAreDelegatedAndRemembered()

	/**
	 * A remembered answer is returned as it is, and nothing else is asked.
	 *
	 * @return void
	 */
	public function testARememberedAnswerShortCircuits(): void {
		$this->answers = ['round-robin' => ['anna']];
		$this->remembered = ['carla'];

		self::assertSame(['carla'], $this->resolver()->resolve(rule: ['strategy' => 'round-robin'], case: ['id' => 'case-1']));
		self::assertCount(1, $this->calls);
	}//end testARememberedAnswerShortCircuits()

	/**
	 * Nobody from the strategy falls back to the single role, except for the hierarchical strategy.
	 *
	 * @return void
	 */
	public function testTheFallbackRole(): void {
		$this->answers = ['round-robin' => [], 'single-role' => ['dirk'], 'hierarchical' => []];

		self::assertSame(['dirk+d'], $this->resolver()->resolve(rule: ['strategy' => 'round-robin', 'fallback' => 'coordinator'], case: ['id' => 'case-1']));
		self::assertSame(['strategy.single-role', ['strategy' => 'single-role', 'roleType' => 'coordinator'], []], $this->calls[2]);

		$this->calls = [];
		self::assertSame([], $this->resolver()->resolve(rule: ['strategy' => 'hierarchical', 'fallback' => 'coordinator'], case: ['id' => 'case-1']));
		self::assertSame(['strategy.hierarchical'], array_values(array_filter(array_column($this->calls, 0), static fn (string $c): bool => str_starts_with($c, 'strategy.'))));
	}//end testTheFallbackRole()

	/**
	 * Nobody at all is remembered and said out loud.
	 *
	 * @return void
	 */
	public function testAnEmptySetIsLogged(): void {
		$this->answers = ['round-robin' => []];
		$rule = ['strategy' => 'round-robin'];

		self::assertSame([], $this->resolver()->resolve(rule: $rule, case: []));
		self::assertSame(
			[
				['cache.get', $rule, ''],
				['strategy.round-robin', $rule, []],
				['delegation', [], []],
				['cache.remember', $rule, '', []],
				['log.info', 'Dossiq: routing rule resolved to empty set', ['event' => 'RoleRoutingEmpty', 'rule' => $rule, 'caseId' => '', 'app' => 'dossiq']],
			],
			$this->calls
		);
	}//end testAnEmptySetIsLogged()

	/**
	 * Refused: an unknown strategy, before anything is read.
	 *
	 * @return void
	 */
	public function testAnUnknownStrategyIsRefused(): void {
		try {
			$this->resolver()->resolve(rule: ['strategy' => 'nope'], case: ['id' => 'case-1']);
			self::fail('Not refused');
		} catch (RoutingStrategyMissingException $e) {
			self::assertSame('Routing strategy "nope" is not registered', $e->getMessage());
		}

		self::assertSame([], $this->calls);
	}//end testAnUnknownStrategyIsRefused()
}//end class
