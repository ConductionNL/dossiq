<?php

/**
 * Unit tests for ZgwRuleLookups.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zgw;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zgw\ZgwRuleLookups;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The store lookups of the ZGW business rules, in the request context.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class ZgwRuleLookupsTest extends TestCase {
	/**
	 * The warnings logged.
	 *
	 * @var array<int, string>
	 */
	private array $warnings = [];

	/**
	 * A store answering from closures, recording each call.
	 *
	 * @param \Closure|null $search Answers a query.
	 * @param \Closure|null $find Answers an id.
	 *
	 * @return object
	 */
	private function store(?\Closure $search = null, ?\Closure $find = null): object {
		return new class($search, $find) {
			/**
			 * Every call, as [method, arguments].
			 *
			 * @var array<int, array>
			 */
			public array $calls = [];

			public function __construct(
				private ?\Closure $search,
				private ?\Closure $find,
			) {
			}//end __construct()

			public function buildSearchQuery(array $requestParams, string $register, string $schema): array {
				return ($requestParams + ['@register' => $register, '@schema' => $schema]);
			}//end buildSearchQuery()

			public function searchObjectsPaginated(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->calls[] = ['search', $query, $_rbac, $_multitenancy];
				return ($this->search)($query);
			}//end searchObjectsPaginated()

			public function find(string $id, string $register, string $schema): mixed {
				$this->calls[] = ['find', $id, $register, $schema];
				return ($this->find)($id);
			}//end find()
		};
	}//end store()

	/**
	 * The lookups, in the context of a request when a store is given.
	 *
	 * @param object|null $store The store.
	 * @param array|null $mappingConfig The mapping configuration.
	 * @param string $schema What every schema key resolves to.
	 *
	 * @return ZgwRuleLookups
	 */
	private function lookups(?object $store, ?array $mappingConfig = ['sourceRegister' => '5', 'sourceSchema' => '50'], string $schema = '51'): ZgwRuleLookups {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturn($schema);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message): void {
				$this->warnings[] = $message;
			}
		);
		$lookups = new ZgwRuleLookups(logger: $logger, settingsService: $settings);
		$lookups->setContext(objectService: $store, mappingConfig: $mappingConfig);

		return $lookups;
	}//end lookups()

	/**
	 * A serialisable entity, as the store may answer one.
	 *
	 * @param array $row What it serialises to.
	 *
	 * @return \JsonSerializable
	 */
	private function entity(array $row): \JsonSerializable {
		return new class($row) implements \JsonSerializable {
			public function __construct(private array $row) {
			}//end __construct()

			public function jsonSerialize(): array {
				return $this->row;
			}//end jsonSerialize()
		};
	}//end entity()

	/**
	 * The context is what the rules class set: its store and its register.
	 *
	 * @return void
	 */
	public function testTheContextIsTheRequests(): void {
		$store = $this->store();

		$this->assertSame($store, $this->lookups(store: $store)->objectService());
		$this->assertSame('5', $this->lookups(store: $store)->register());
		$this->assertSame('', $this->lookups(store: $store, mappingConfig: null)->register());
		$this->assertNull($this->lookups(store: null)->objectService());
	}//end testTheContextIsTheRequests()

	/**
	 * One object by a field: the first hit's id, from an array or an entity, or null.
	 *
	 * @return void
	 */
	public function testOneObjectByField(): void {
		$store = $this->store(search: fn (array $query): array => ['results' => [$this->entity(['@self' => ['id' => 'a']]), ['id' => 'b']]]);

		$this->assertSame('a', $this->lookups(store: $store)->findObjectByField(register: '5', schema: '9', field: 'name', value: 'Brief'));
		$this->assertSame([['search', ['name' => 'Brief', '_limit' => 1, '@register' => '5', '@schema' => '9'], true, true]], $store->calls);
		$this->assertNull($this->lookups(store: $this->store(search: static fn (): array => []))->findObjectByField('5', '9', 'name', 'x'));

		$down = $this->store(
			search: static function (): array {
				throw new \RuntimeException('down');
			}
		);
		$this->assertNull($this->lookups(store: $down)->findObjectByField('5', '9', 'name', 'x'));
		$this->assertSame(['Reference resolution failed: down'], $this->warnings);
	}//end testOneObjectByField()

	/**
	 * Every object by a field: each id, a hundred at most, none when the search fails.
	 *
	 * @return void
	 */
	public function testEveryObjectByField(): void {
		$store = $this->store(search: fn (array $query): array => ['results' => [['id' => 'a'], $this->entity(['@self' => ['id' => 'b']]), ['title' => 'no id']]]);

		$this->assertSame(['a', 'b'], $this->lookups(store: $store)->findAllObjectsByField(register: '5', schema: '9', field: 'name', value: 'Brief'));
		$this->assertSame(100, $store->calls[0][1]['_limit']);

		$down = $this->store(
			search: static function (): array {
				throw new \RuntimeException('down');
			}
		);
		$this->assertSame([], $this->lookups(store: $down)->findAllObjectsByField('5', '9', 'name', 'x'));
		$this->assertSame(['Reference resolution failed: down'], $this->warnings);
	}//end testEveryObjectByField()

	/**
	 * An object by uuid is read in the request's register and the schema the key names.
	 *
	 * @return void
	 */
	public function testAnObjectByUuidAndSchemaKey(): void {
		$store = $this->store(find: fn (string $id): mixed => (['u-1' => ['id' => 'u-1'], 'u-2' => $this->entity(['id' => 'u-2'])][$id] ?? throw new \RuntimeException('gone')));

		$this->assertSame(['id' => 'u-1'], $this->lookups(store: $store)->findBySchemaKey(uuid: 'u-1', schemaKey: 'case_schema'));
		$this->assertSame(['id' => 'u-2'], $this->lookups(store: $store)->findBySchemaKey(uuid: 'u-2', schemaKey: 'case_schema'));
		$this->assertNull($this->lookups(store: $store)->findBySchemaKey(uuid: 'u-3', schemaKey: 'case_schema'));
		$this->assertSame(['find', 'u-1', '5', '51'], $store->calls[0]);
		$this->assertCount(3, $store->calls);

		$this->assertNull($this->lookups(store: null)->findBySchemaKey('u-1', 'case_schema'));
		$this->assertNull($this->lookups(store: $store, mappingConfig: [])->findBySchemaKey('u-1', 'case_schema'));
		$this->assertNull($this->lookups(store: $store, schema: '')->findBySchemaKey('u-1', 'case_schema'));
		$this->assertCount(3, $store->calls);
	}//end testAnObjectByUuidAndSchemaKey()

	/**
	 * A pair is taken when a hit on the first value carries the second, also as OpenRegister
	 * coerces it; the search runs on the first value only, outside the caller's own access.
	 *
	 * @return void
	 */
	public function testAPairIsTakenWhenAHitCarriesTheSecondValue(): void {
		$hits  = [['sourceOrganisation' => '123'], $this->entity(['sourceOrganisation' => ''])];
		$store = $this->store(search: static fn (array $query): array => ['results' => $hits]);
		$taken = fn (string $second): bool => $this->lookups(store: $store)->fieldPairExists(
			field1Value: 'ID-1',
			field1Search: 'identifier',
			field2Value: $second,
			field2Search: 'sourceOrganisation'
		);

		$this->assertTrue($taken('123'));
		$this->assertSame(['search', ['@self' => ['register' => 5, 'schema' => 50], 'identifier' => 'ID-1'], false, false], $store->calls[0]);
		$this->assertTrue($taken('999'), 'An empty stored value counts as coerced, so as the same.');
		$this->assertTrue($taken(''));

		$other = $this->store(search: static fn (array $query): array => ['results' => [['sourceOrganisation' => '123'], ['sourceOrganisation' => '0']]]);
		$this->assertFalse($this->lookups(store: $other)->fieldPairExists('ID-1', 'identifier', '999', 'sourceOrganisation'));
		$this->assertTrue($this->lookups(store: $other)->fieldPairExists('ID-1', 'identifier', '000000000', 'sourceOrganisation'));
		$this->assertFalse($this->lookups(store: $this->store(search: static fn (): array => []))->fieldPairExists('ID-1', 'identifier', '123', 'sourceOrganisation'));
	}//end testAPairIsTakenWhenAHitCarriesTheSecondValue()

	/**
	 * Nothing is searched without a first value, a store, or a register and schema, and a search
	 * that fails is logged and counts as free.
	 *
	 * @return void
	 */
	public function testAPairThatCannotBeLookedUpCountsAsFree(): void {
		$store = $this->store(search: static fn (array $query): array => ['results' => [['org' => '1']]]);

		$this->assertFalse($this->lookups(store: $store)->fieldPairExists('', 'identifier', '1', 'org'));
		$this->assertFalse($this->lookups(store: null)->fieldPairExists('ID-1', 'identifier', '1', 'org'));
		$this->assertFalse($this->lookups(store: $store, mappingConfig: ['sourceRegister' => '5'])->fieldPairExists('ID-1', 'identifier', '1', 'org'));
		$this->assertFalse($this->lookups(store: $store, mappingConfig: ['sourceSchema' => '50'])->fieldPairExists('ID-1', 'identifier', '1', 'org'));
		$this->assertSame([], $store->calls);

		$down = $this->store(
			search: static function (): array {
				throw new \RuntimeException('down');
			}
		);
		$this->assertFalse($this->lookups(store: $down)->fieldPairExists('ID-1', 'identifier', '1', 'org'));
		$this->assertSame(['Uniqueness check failed: down'], $this->warnings);
	}//end testAPairThatCannotBeLookedUpCountsAsFree()
}//end class
