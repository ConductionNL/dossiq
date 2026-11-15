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
use OCA\Dossiq\Service\Zgw\LookupNotCompleted;
use OCA\Dossiq\Service\Zgw\ZgwRuleLookups;
use OCA\OpenRegister\Exception\NotAuthorizedException;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * The store lookups of the ZGW business rules, in the request context.
 *
 * @covers \OCA\Dossiq\Service\Zgw\ZgwRuleLookups
 * @uses   \OCA\Dossiq\Service\Zgw\LookupNotCompleted
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class ZgwRuleLookupsTest extends TestCase {
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
		$lookups = new ZgwRuleLookups(settingsService: $settings);
		$lookups->setContext(objectService: $store, mappingConfig: $mappingConfig);

		return $lookups;
	}//end lookups()

	/**
	 * A store whose every search and read fails.
	 *
	 * @return object
	 */
	private function downStore(): object {
		$down = static function (): never {
			throw new \RuntimeException('down');
		};

		return $this->store(search: $down, find: $down);
	}//end downStore()

	/**
	 * What a lookup threw, as [what it was reading, why], or null when it answered.
	 *
	 * @param \Closure $lookup The lookup.
	 *
	 * @return array{0: string, 1: string}|null
	 */
	private function failure(\Closure $lookup): ?array {
		try {
			$lookup();
		} catch (LookupNotCompleted $e) {
			return [$e->lookup, $e->getMessage()];
		}

		return null;
	}//end failure()

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
		$this->assertSame(7, $this->lookups(store: $store, mappingConfig: ['sourceRegister' => 7])->register());
		$this->assertSame('', $this->lookups(store: $store, mappingConfig: null)->register());
		$this->assertNull($this->lookups(store: null)->objectService());
	}//end testTheContextIsTheRequests()

	/**
	 * One object by a field: the first hit's id, from an array or an entity, or null; a search
	 * that fails throws, and nothing is searched without a store.
	 *
	 * @return void
	 */
	public function testOneObjectByField(): void {
		$store = $this->store(search: fn (array $query): array => ['results' => [$this->entity(['@self' => ['id' => 'a']]), ['id' => 'b']]]);

		$this->assertSame('a', $this->lookups(store: $store)->findObjectByField(register: '5', schema: '9', field: 'name', value: 'Brief'));
		$this->assertSame([['search', ['name' => 'Brief', '_limit' => 1, '@register' => '5', '@schema' => '9'], true, true]], $store->calls);
		$this->assertNull($this->lookups(store: $this->store(search: static fn (): array => []))->findObjectByField('5', '9', 'name', 'x'));

		$this->assertSame(
			['reference name "x"', 'down'],
			$this->failure(fn () => $this->lookups(store: $this->downStore())->findObjectByField('5', '9', 'name', 'x'))
		);
		$this->assertNull($this->lookups(store: null)->findObjectByField('5', '9', 'name', 'x'));
	}//end testOneObjectByField()

	/**
	 * Every object by a field: each id, a hundred at most; a search that fails throws, and
	 * nothing is searched without a store.
	 *
	 * @return void
	 */
	public function testEveryObjectByField(): void {
		$store = $this->store(search: fn (array $query): array => ['results' => [['id' => 'a'], $this->entity(['@self' => ['id' => 'b']]), ['title' => 'no id']]]);

		$this->assertSame(['a', 'b'], $this->lookups(store: $store)->findAllObjectsByField(register: '5', schema: '9', field: 'name', value: 'Brief'));
		$this->assertSame(100, $store->calls[0][1]['_limit']);

		$this->assertSame(
			['reference name "x"', 'down'],
			$this->failure(fn () => $this->lookups(store: $this->downStore())->findAllObjectsByField('5', '9', 'name', 'x'))
		);
		$this->assertSame([], $this->lookups(store: null)->findAllObjectsByField('5', '9', 'name', 'x'));
	}//end testEveryObjectByField()

	/**
	 * The strict read tells "none" from "could not look": an empty answer is an empty list, a search
	 * that fails throws, and so does a read without a store.
	 *
	 * @return void
	 */
	public function testTheStrictReadThrowsWhenItCannotLook(): void {
		$some = $this->store(search: static fn (array $query): array => ['results' => [['id' => 'a']]]);
		$none = $this->store(search: static fn (array $query): array => ['results' => []]);
		$down = $this->store(
			search: static function (): array {
				throw new \RuntimeException('down');
			}
		);

		$this->assertSame(['a'], $this->lookups(store: $some)->idsByField(register: '5', schema: '9', field: 'document', value: 'x'));
		$this->assertSame([], $this->lookups(store: $none)->idsByField(register: '5', schema: '9', field: 'document', value: 'x'));

		$thrown = [];
		foreach ([$down, null] as $store) {
			try {
				$this->lookups(store: $store)->idsByField(register: '5', schema: '9', field: 'document', value: 'x');
			} catch (\RuntimeException $e) {
				$thrown[] = $e->getMessage();
			}
		}

		$this->assertSame(['down', 'No object service in the request context'], $thrown);
	}//end testTheStrictReadThrowsWhenItCannotLook()

	/**
	 * An object by uuid is read in the request's register and the schema the key names. An object
	 * the store does not hold, or does not show, is null; a read that fails for another reason throws.
	 *
	 * @return void
	 */
	public function testAnObjectByUuidAndSchemaKey(): void {
		$store = $this->store(find: fn (string $id): mixed => (['u-1' => ['id' => 'u-1'], 'u-2' => $this->entity(['id' => 'u-2'])][$id] ?? throw new DoesNotExistException('gone')));

		$this->assertSame(['id' => 'u-1'], $this->lookups(store: $store)->findBySchemaKey(uuid: 'u-1', schemaKey: 'case_schema'));
		$this->assertSame(['id' => 'u-2'], $this->lookups(store: $store)->findBySchemaKey(uuid: 'u-2', schemaKey: 'case_schema'));
		$this->assertNull($this->lookups(store: $store)->findBySchemaKey(uuid: 'u-3', schemaKey: 'case_schema'));
		$this->assertSame(['find', 'u-1', '5', '51'], $store->calls[0]);
		$this->assertCount(3, $store->calls);

		$this->assertNull($this->lookups(store: null)->findBySchemaKey('u-1', 'case_schema'));
		$this->assertNull($this->lookups(store: $store, mappingConfig: [])->findBySchemaKey('u-1', 'case_schema'));
		$this->assertNull($this->lookups(store: $store, schema: '')->findBySchemaKey('u-1', 'case_schema'));
		$this->assertCount(3, $store->calls);

		$hidden = $this->store(find: static fn (): never => throw new NotAuthorizedException());
		$this->assertNull($this->lookups(store: $hidden)->findBySchemaKey('u-1', 'case_schema'), 'a refused read is an object the rule cannot use');
		$this->assertNull($this->lookups(store: $this->store(find: static fn (): mixed => null))->findBySchemaKey('u-1', 'case_schema'));
		$this->assertSame(
			['case_schema object u-1', 'down'],
			$this->failure(fn () => $this->lookups(store: $this->downStore())->findBySchemaKey('u-1', 'case_schema'))
		);
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
	 * Nothing is searched, and the pair is free, without a first value, a store, or a register and
	 * schema. A search that fails throws: a duplicate nobody could look for is not a free pair.
	 *
	 * @return void
	 */
	public function testAPairIsFreeOnlyWhenTheSearchRan(): void {
		$store = $this->store(search: static fn (array $query): array => ['results' => [['org' => '1']]]);

		$this->assertFalse($this->lookups(store: $store)->fieldPairExists('', 'identifier', '1', 'org'));
		$this->assertFalse($this->lookups(store: null)->fieldPairExists('ID-1', 'identifier', '1', 'org'));
		$this->assertFalse($this->lookups(store: $store, mappingConfig: ['sourceRegister' => '5'])->fieldPairExists('ID-1', 'identifier', '1', 'org'));
		$this->assertFalse($this->lookups(store: $store, mappingConfig: ['sourceSchema' => '50'])->fieldPairExists('ID-1', 'identifier', '1', 'org'));
		$this->assertSame([], $store->calls);

		$this->assertSame(
			['uniqueness of identifier "ID-1"', 'down'],
			$this->failure(fn () => $this->lookups(store: $this->downStore())->fieldPairExists('ID-1', 'identifier', '1', 'org'))
		);
	}//end testAPairIsFreeOnlyWhenTheSearchRan()
}//end class
