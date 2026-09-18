<?php

/**
 * A stand-in for OpenRegister's ObjectService, for the split surface tests.
 *
 * Its own file rather than a class beside one test, because a fake defined
 * inside a test file exists only when that file is loaded: a `--filter` run of
 * a sibling suite then fails with a missing class and says nothing at all
 * about the code under test.
 *
 * Written out rather than stubbed so no method the real service lacks can be
 * invented here. These four are the ones the performer actually calls.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

/**
 * Holds cases, documents and parties in memory.
 */
final class FakeSplitObjectService {
	/**
	 * Rows by schema and uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	private array $rows = [];

	/**
	 * Put a row in the store.
	 *
	 * @param string               $schema  The schema id.
	 * @param string               $uuid    The uuid.
	 * @param array<string, mixed> $payload The row.
	 *
	 * @return void
	 */
	public function store(string $schema, string $uuid, array $payload): void {
		$this->rows[$schema][$uuid] = $payload;
	}//end store()

	/**
	 * Read one row back.
	 *
	 * @param string $schema The schema id.
	 * @param string $uuid   The uuid.
	 *
	 * @return array<string, mixed> The row.
	 */
	public function read(string $schema, string $uuid): array {
		return ($this->rows[$schema][$uuid] ?? []);
	}//end read()

	/**
	 * Every row of one schema.
	 *
	 * @param string $schema The schema id.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function all(string $schema): array {
		return array_values(($this->rows[$schema] ?? []));
	}//end all()

	/**
	 * Find one row.
	 *
	 * @param int|string      $id       The uuid.
	 * @param array|null      $_extend  Unused.
	 * @param bool            $files    Unused.
	 * @param int|string|null $register Unused.
	 * @param int|string|null $schema   The schema id.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	public function find(
		int|string $id,
		?array $_extend = null,
		bool $files = false,
		int|string|null $register = null,
		int|string|null $schema = null,
	): ?array {
		return ($this->rows[(string)$schema][(string)$id] ?? null);
	}//end find()

	/**
	 * Store a row, minting an id when it has none.
	 *
	 * @param array           $object   The row.
	 * @param int|string|null $register Unused.
	 * @param int|string|null $schema   The schema id.
	 * @param string|null     $uuid     The uuid, when updating.
	 *
	 * @return array<string, mixed> The stored row.
	 */
	public function saveObject(
		array $object,
		int|string|null $register = null,
		int|string|null $schema = null,
		?string $uuid = null,
	): array {
		$key = (string)$schema;
		$id = (string)(($uuid ?? '') ?: ($object['id'] ?? ('new-' . (count($this->rows[$key] ?? []) + 1))));
		$object['id'] = $id;
		$this->rows[$key][$id] = $object;

		return $object;
	}//end saveObject()

	/**
	 * Write a few fields onto one row.
	 *
	 * @param string          $objectId The uuid.
	 * @param array           $data     The fields.
	 * @param int|string|null $register Unused.
	 * @param int|string|null $schema   The schema id.
	 *
	 * @return array<string, mixed>|null The stored row.
	 */
	public function patchObject(
		string $objectId,
		array $data,
		int|string|null $register = null,
		int|string|null $schema = null,
	): ?array {
		$key = (string)$schema;
		if (isset($this->rows[$key][$objectId]) === false) {
			return null;
		}

		$this->rows[$key][$objectId] = array_merge($this->rows[$key][$objectId], $data);

		return $this->rows[$key][$objectId];
	}//end patchObject()

	/**
	 * Search one schema, applying the field filters given.
	 *
	 * @param array<string, mixed> $query The `@self` block plus filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function searchObjects(array $query): array {
		$schema = (string)($query['@self']['schema'] ?? '');
		unset($query['@self']);

		$matches = [];
		foreach (($this->rows[$schema] ?? []) as $row) {
			foreach ($query as $field => $value) {
				if ((string)($row[$field] ?? '') !== (string)$value) {
					continue 2;
				}
			}

			$matches[] = $row;
		}

		return $matches;
	}//end searchObjects()
}//end class
