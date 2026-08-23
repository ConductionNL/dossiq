<?php

/**
 * FakeTermijnStore fixture
 *
 * Tiny in-memory ObjectService fake reused across the termijnbewaking +
 * archief-edepot unit tests. Lives in the global namespace (same as the
 * original declaration in TermijnServiceTest.php) so that every test file
 * referencing `new FakeTermijnStore()` keeps working unchanged.
 *
 * Loaded via tests/bootstrap.php so individual test files can run
 * standalone — previously the class was declared at the bottom of
 * TermijnServiceTest.php, which meant any --filter run against a single
 * other test file fataled with "Class FakeTermijnStore not found".
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests
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

namespace OCA\Dossiq\Tests\Unit\Service;

if (class_exists(FakeTermijnStore::class, false) === true) {
	return;
}

/**
 * Tiny in-memory ObjectService fake reused by all termijnbewaking +
 * archief-edepot unit tests.
 *
 * Originally lived at the bottom of TermijnServiceTest.php inside the
 * `OCA\Dossiq\Tests\Unit\Service` namespace. Extracted to this fixture
 * file so every test file referencing `new FakeTermijnStore()` resolves
 * standalone — the namespace pin keeps the existing 10 call sites
 * compiling without import changes.
 */
class FakeTermijnStore {
	/**
	 * Object store, keyed by schema slug then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * Auto-increment id sequence.
	 *
	 * @var int
	 */
	private int $seq = 0;

	/**
	 * Find a single object by id within a register/schema.
	 *
	 * @param string $id Id.
	 * @param string $register Register.
	 * @param string $schema Schema.
	 *
	 * @return array<string, mixed>|null
	 */
	public function find(string $id, string $register = '', string $schema = ''): ?array {
		return ($this->store[$schema][$id] ?? null);
	}

	/**
	 * Equality-filter object search.
	 *
	 * OpenRegister's real `searchObjects()`/`searchObjectsBySlug()` treat
	 * `_limit`/`_offset` as pagination keys passed straight through — NOT
	 * object-field equality filters (see SearchesObjects trait docblock).
	 * They are stripped here before filtering so a caller that paginates
	 * (e.g. `['_limit' => 2000]`) doesn't zero out every row by matching
	 * against a `_limit` field none of them have.
	 *
	 * @param string $register Register.
	 * @param string $schema Schema.
	 * @param array<string, mixed> $filters Filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function findObjects(string $register, string $schema, array $filters = []): array {
		$rows = array_values($this->store[$schema] ?? []);

		unset($filters['_limit'], $filters['_offset']);
		if (count($filters) === 0) {
			return $rows;
		}

		return array_values(array_filter(
			$rows,
			static function (array $row) use ($filters): bool {
				foreach ($filters as $key => $value) {
					if (($row[$key] ?? null) !== $value) {
						return false;
					}
				}
				return true;
			},
		));
	}

	/**
	 * Slug-aware search bridge mirroring OpenRegister
	 * ObjectService::searchObjectsBySlug().
	 *
	 * @param string $registerSlug Register slug.
	 * @param string $schemaSlug Schema slug.
	 * @param array<string, mixed> $filters Object-field filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array {
		return $this->findObjects($registerSlug, $schemaSlug, $filters);
	}

	/**
	 * Numeric-ID search bridge mirroring OpenRegister
	 * ObjectService::searchObjects(). The SearchesObjects trait packs
	 * register/schema into a `@self` block and keeps object-field filters
	 * at the top level.
	 *
	 * @param array<string, mixed> $query Query with `@self` register/schema plus field filters.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function searchObjects(array $query = []): array {
		$self = ($query['@self'] ?? []);
		$schema = (string)($self['schema'] ?? '');
		unset($query['@self']);

		return $this->findObjects('', $schema, $query);
	}

	/**
	 * Persist (insert or update) an object by id.
	 *
	 * @param string $register Register.
	 * @param string $schema Schema.
	 * @param array<string, mixed> $object Object.
	 *
	 * @return array<string, mixed>
	 */
	public function saveObject(string $register, string $schema, array $object): array {
		if (empty($object['id']) === true) {
			$this->seq++;
			$object['id'] = $schema . '-' . $this->seq;
		}
		$this->store[$schema][$object['id']] = $object;
		return $object;
	}
}
