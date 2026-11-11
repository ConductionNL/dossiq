<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use JsonSerializable;
use OCA\Dossiq\Service\RoleResolverService;
use OCA\Dossiq\Service\Routing\AreaRouting;
use OCA\Dossiq\Service\Routing\RoleDelegationResolver;
use OCA\Dossiq\Service\Routing\RoleResolutionCache;
use OCA\Dossiq\Service\Routing\RoutingStrategyInterface;
use OCA\Dossiq\Service\Routing\StrategyRegistry;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Which roles the strategy is handed: how they are read, and when they are not read at all.
 */
class RoleResolverCaseRolesTest extends TestCase {

	/**
	 * The roles the strategy was handed.
	 *
	 * @var array<int, mixed>|null
	 */
	public ?array $handed = null;

	/**
	 * The configs findAll was called with.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $reads = [];

	/**
	 * Resolve one rule for `case-1` over a role register holding `$records`.
	 *
	 * @param array<int, mixed>     $records The stored records.
	 * @param array<string, string> $config  The config values.
	 *
	 * @return void
	 */
	private function resolveOver(array $records, array $config): void {
		$test = $this;
		$objects = new class ($test, $records) {
			/**
			 * @param RoleResolverCaseRolesTest $test    The test.
			 * @param array<int, mixed>         $records The records.
			 */
			public function __construct(private RoleResolverCaseRolesTest $test, private array $records) {
			}

			/**
			 * @param array<string, mixed> $config The read.
			 *
			 * @return array<int, mixed> The records.
			 */
			public function findAll(array $config): array {
				$this->test->reads[] = $config;

				return $this->records;
			}
		};
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ($config[$key] ?? ''));

		$strategy = new class ($test) implements RoutingStrategyInterface {
			/**
			 * @param RoleResolverCaseRolesTest $test The test.
			 */
			public function __construct(private RoleResolverCaseRolesTest $test) {
			}

			/**
			 * @return string The name.
			 */
			public function name(): string {
				return 'single-role';
			}

			/**
			 * @param array<string, mixed> $rule  The rule.
			 * @param array<string, mixed> $case  The case.
			 * @param array<int, mixed>    $roles The roles.
			 *
			 * @return array<int, string> The members.
			 */
			public function resolve(array $rule, array $case, array $roles): array {
				$this->test->handed = $roles;

				return ['anna'];
			}
		};
		$registry = $this->createMock(StrategyRegistry::class);
		$registry->method('has')->willReturn(true);
		$registry->method('get')->willReturn($strategy);
		$cache = $this->createMock(RoleResolutionCache::class);
		$cache->method('get')->willReturn(null);
		$delegation = $this->createMock(RoleDelegationResolver::class);
		$delegation->method('apply')->willReturnArgument(0);

		(new RoleResolverService(
			registry: $registry,
			settingsService: $settings,
			cache: $cache,
			delegation: $delegation,
			area: new AreaRouting(),
			logger: $this->createMock(LoggerInterface::class),
		))->resolve(rule: ['strategy' => 'single-role', 'roleType' => 'behandelaar'], case: ['id' => 'case-1']);
	}//end resolveOver()

	/**
	 * Every kind of stored record becomes a row; one that reads as nothing is left out.
	 *
	 * @return void
	 */
	public function testEveryRecordShapeIsRead(): void {
		$serialisable = new class implements JsonSerializable {
			/**
			 * @return array<string, string> The row.
			 */
			public function jsonSerialize(): array {
				return ['roleType' => 'json'];
			}
		};
		$arrayable = new class {
			/**
			 * @return array<string, string> The row.
			 */
			public function toArray(): array {
				return ['roleType' => 'arr'];
			}
		};
		$plain = new class {
			/**
			 * @var string
			 */
			public string $roleType = 'plain';
		};

		$this->resolveOver(
			records: [['roleType' => 'row'], $serialisable, $arrayable, $plain, 5, []],
			config: ['register' => 'dossiq', 'role_schema' => 'role'],
		);

		self::assertSame([['filters' => ['register' => 'dossiq', 'schema' => 'role', 'case' => 'case-1']]], $this->reads);
		self::assertSame([['roleType' => 'row'], ['roleType' => 'json'], ['roleType' => 'arr'], ['roleType' => 'plain']], $this->handed);
	}//end testEveryRecordShapeIsRead()

	/**
	 * An unconfigured register or role schema reads nothing and hands no roles.
	 *
	 * @return void
	 */
	public function testAnUnconfiguredRegisterReadsNothing(): void {
		$this->resolveOver(records: [['roleType' => 'row']], config: ['register' => 'dossiq']);
		self::assertSame([], $this->reads);
		self::assertSame([], $this->handed);

		$this->resolveOver(records: [['roleType' => 'row']], config: ['role_schema' => 'role']);
		self::assertSame([], $this->reads);
		self::assertSame([], $this->handed);
	}//end testAnUnconfiguredRegisterReadsNothing()
}//end class
