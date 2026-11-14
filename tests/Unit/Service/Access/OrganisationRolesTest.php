<?php

/**
 * Unit tests for reading organisation roles and resolving a role's keys.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Access
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Access;

use OCA\Dossiq\Service\Access\OrganisationRoles;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * The roles are read from the configured register and schema, scoped or not.
 *
 * @covers \OCA\Dossiq\Service\Access\OrganisationRoles
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 */
class OrganisationRolesTest extends TestCase {

	/**
	 * An object service that records the searches it is asked for.
	 *
	 * @param array<int, mixed> $rows What every search answers.
	 *
	 * @return object The object service stand-in, with a public `$calls` log.
	 */
	private function objectService(array $rows): object {
		return new class($rows) {
			/**
			 * The searches asked for, in order.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $calls = [];

			/**
			 * Constructor.
			 *
			 * @param array<int, mixed> $rows What to answer.
			 */
			public function __construct(private readonly array $rows) {
			}

			/**
			 * Search by register and schema slug.
			 *
			 * @param string $register The register slug.
			 * @param string $schema   The schema slug.
			 * @param array  $filters  The filters.
			 * @param bool   ...$scope The named scope flags.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters, bool ...$scope): array {
				$this->calls[] = ['bySlug' => [$register, $schema, $filters, $scope]];
				return $this->rows;
			}

			/**
			 * Search by numeric ids.
			 *
			 * @param array $query    The query.
			 * @param bool  ...$scope The named scope flags.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function searchObjects(array $query, bool ...$scope): array {
				$this->calls[] = ['byId' => [$query, $scope]];
				return $this->rows;
			}
		};
	}//end objectService()

	/**
	 * A settings service answering the given config and object service.
	 *
	 * @param object|null           $objectService The object service.
	 * @param array<string, string> $config        Key to value.
	 *
	 * @return OrganisationRoles The service under test.
	 */
	private function roles(?object $objectService, array $config): OrganisationRoles {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($config[$key] ?? $default)
		);

		return new OrganisationRoles(settingsService: $settings);
	}//end roles()

	/**
	 * Roles are searched by slug, scoped to the caller, capped at 500.
	 *
	 * @return void
	 */
	public function testAllSearchesTheRoleSchemaScoped(): void {
		$objects = $this->objectService(rows: [['id' => 'r-1', 'roleName' => 'Intaker']]);
		$roles = $this->roles(objectService: $objects, config: ['register' => 'dossiq', 'organisatie_rol_schema' => 'organisatieRol']);

		self::assertSame([['id' => 'r-1', 'roleName' => 'Intaker']], $roles->all());
		self::assertSame([['bySlug' => ['dossiq', 'organisatieRol', ['_limit' => 500], []]]], $objects->calls);
	}//end testAllSearchesTheRoleSchemaScoped()

	/**
	 * The unscoped read switches RBAC and multitenancy off.
	 *
	 * @return void
	 */
	public function testAllUnscopedSwitchesTheScopeOff(): void {
		$objects = $this->objectService(rows: []);
		$roles = $this->roles(objectService: $objects, config: ['register' => 'dossiq', 'organisatie_rol_schema' => 'organisatieRol']);

		self::assertSame([], $roles->allUnscoped());
		self::assertSame(
			[['bySlug' => ['dossiq', 'organisatieRol', ['_limit' => 500], ['_rbac' => false, '_multitenancy' => false]]]],
			$objects->calls
		);
	}//end testAllUnscopedSwitchesTheScopeOff()

	/**
	 * Numeric ids are searched through `@self`, and objects are serialised to arrays.
	 *
	 * @return void
	 */
	public function testNumericIdsSearchThroughSelf(): void {
		$entity = new class {
			/**
			 * Serialise.
			 *
			 * @return array<string, string> The row.
			 */
			public function jsonSerialize(): array {
				return ['id' => 'r-2'];
			}
		};
		$objects = $this->objectService(rows: [$entity, 'not a row']);
		$roles = $this->roles(objectService: $objects, config: ['register' => '4', 'organisatie_rol_schema' => '12']);

		self::assertSame([['id' => 'r-2']], $roles->all());
		self::assertSame(
			[['byId' => [['_limit' => 500, '@self' => ['register' => 4, 'schema' => 12]], []]]],
			$objects->calls
		);
	}//end testNumericIdsSearchThroughSelf()

	/**
	 * Without OpenRegister, a register or a role schema, nothing is searched.
	 *
	 * @return void
	 */
	public function testAnIncompleteConfigurationReadsNothing(): void {
		$full = ['register' => 'dossiq', 'organisatie_rol_schema' => 'organisatieRol'];

		self::assertSame([], $this->roles(objectService: null, config: $full)->all());

		foreach (['register', 'organisatie_rol_schema'] as $missing) {
			$objects = $this->objectService(rows: [['id' => 'r-1']]);
			$config = $full;
			unset($config[$missing]);

			self::assertSame([], $this->roles(objectService: $objects, config: $config)->allUnscoped(), $missing);
			self::assertSame([], $objects->calls, $missing);
		}
	}//end testAnIncompleteConfigurationReadsNothing()

	/**
	 * A role named by id or by name resolves to both, without empty keys.
	 *
	 * @return void
	 */
	public function testKeysOfAnswersBothKeysOfTheMatchingRole(): void {
		$roles = $this->roles(objectService: null, config: []);
		$list = [
			['id' => 'r-1', 'roleName' => 'Intaker'],
			['id' => 'r-2', 'roleName' => ''],
		];

		self::assertSame(['r-1', 'Intaker'], $roles->keysOf(role: 'Intaker', roles: $list));
		self::assertSame(['r-1', 'Intaker'], $roles->keysOf(role: 'r-1', roles: $list));
		self::assertSame(['r-2'], $roles->keysOf(role: 'r-2', roles: $list));
	}//end testKeysOfAnswersBothKeysOfTheMatchingRole()

	/**
	 * An unknown role, or an empty list, answers the role itself.
	 *
	 * @return void
	 */
	public function testKeysOfAnUnknownRoleIsTheRoleItself(): void {
		$roles = $this->roles(objectService: null, config: []);

		self::assertSame(['Onbekend'], $roles->keysOf(role: 'Onbekend', roles: [['id' => 'r-1', 'roleName' => 'Intaker']]));
		self::assertSame(['x'], $roles->keysOf(role: 'x', roles: []));
	}//end testKeysOfAnUnknownRoleIsTheRoleItself()
}//end class
