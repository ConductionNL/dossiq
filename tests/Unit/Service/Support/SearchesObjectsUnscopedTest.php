<?php
/**
 * SearchesObjects Unscoped Read Unit Tests
 *
 * A seed step reads the whole register regardless of the caller's RBAC and
 * tenancy; every other caller keeps the scoped default.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\Support\SearchesObjects;
use PHPUnit\Framework\TestCase;

/**
 * A host exposing the trait's search seam.
 */
class SearchingHost {

	use SearchesObjects;


	/**
	 * @param object     $objectService The store.
	 * @param int|string $register      Register id or slug.
	 * @param int|string $schema        Schema id or slug.
	 * @param bool       $unscoped      Whether to read past the caller's scope.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function search(object $objectService, int|string $register, int|string $schema, bool $unscoped): array {
		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['title' => 'X'],
			unscoped: $unscoped
		);
	}//end search()
}//end class

/**
 * @covers \OCA\Dossiq\Service\Support\SearchesObjects
 */
class SearchesObjectsUnscopedTest extends TestCase {


	/**
	 * A store that records the access flags each search carried.
	 *
	 * @return object The store double.
	 */
	private function store(): object {
		return new class {
			/**
			 * @var array<string, mixed> The last call: method and flags.
			 */
			public array $call = [];


			/**
			 * @param array<string, mixed> $query         The query.
			 * @param bool                 $_rbac         RBAC flag.
			 * @param bool                 $_multitenancy Tenancy flag.
			 *
			 * @return array<int, array<string, mixed>> No rows.
			 */
			public function searchObjects(array $query, bool $_rbac = true, bool $_multitenancy = true): array {
				$this->call = ['method' => 'searchObjects', 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return [];
			}//end searchObjects()


			/**
			 * @param string               $registerSlug  The register slug.
			 * @param string               $schemaSlug    The schema slug.
			 * @param array<string, mixed> $filters       The filters.
			 * @param bool                 $_rbac         RBAC flag.
			 * @param bool                 $_multitenancy Tenancy flag.
			 *
			 * @return array<int, array<string, mixed>> No rows.
			 */
			public function searchObjectsBySlug(
				string $registerSlug,
				string $schemaSlug,
				array $filters = [],
				bool $_rbac = true,
				bool $_multitenancy = true,
			): array {
				$this->call = ['method' => 'searchObjectsBySlug', 'rbac' => $_rbac, 'multitenancy' => $_multitenancy];
				return [];
			}//end searchObjectsBySlug()
		};
	}//end store()


	/**
	 * An unscoped read switches both flags off, on the numeric and the slug path.
	 *
	 * @return void
	 */
	public function testAnUnscopedReadDropsRbacAndTenancy(): void {
		$store = $this->store();
		(new SearchingHost())->search(objectService: $store, register: 18, schema: 23, unscoped: true);
		$this->assertSame(expected: ['method' => 'searchObjects', 'rbac' => false, 'multitenancy' => false], actual: $store->call);

		$store = $this->store();
		(new SearchingHost())->search(objectService: $store, register: 'dossiq', schema: 'caseType', unscoped: true);
		$this->assertSame(expected: ['method' => 'searchObjectsBySlug', 'rbac' => false, 'multitenancy' => false], actual: $store->call);
	}//end testAnUnscopedReadDropsRbacAndTenancy()


	/**
	 * A scoped read keeps the store's defaults, and passes no flags at all so a
	 * store without those parameters still accepts the call.
	 *
	 * @return void
	 */
	public function testAScopedReadPassesNoFlags(): void {
		$store = new class {
			/**
			 * @var bool Whether the search was reached.
			 */
			public bool $called = false;


			/**
			 * @param array<string, mixed> $query The query.
			 *
			 * @return array<int, array<string, mixed>> No rows.
			 */
			public function searchObjects(array $query): array {
				$this->called = true;
				return [];
			}//end searchObjects()
		};

		(new SearchingHost())->search(objectService: $store, register: 18, schema: 23, unscoped: false);

		$this->assertTrue(condition: $store->called);
	}//end testAScopedReadPassesNoFlags()
}//end class
