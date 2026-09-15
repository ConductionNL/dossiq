<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An OpenRegister object store that lives in an array.
 *
 * 🔑 IT IS A REAL STORE, NOT A METHOD STUB. The handover reads a case, writes
 * it, reads it back through a second service, and the whole point of several
 * of these tests is that what came out is what went in. A per-call `willReturn`
 * cannot express that: it answers the same row whatever was written, so a save
 * that dropped a field would pass. This one keeps the rows.
 *
 * It implements the four methods the handover path actually calls, with the
 * named-parameter signatures the real service has. A narrower double would let
 * a call with a wrong argument name pass here and fail in production, which is
 * exactly what tests/Stubs/Contract/ObjectServiceInterface.php exists to stop.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

/**
 * An in-memory stand-in for OpenRegister's ObjectService.
 */
class InMemoryRegister {

	/**
	 * Every stored row, keyed by schema and then by uuid.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $rows = [];

	/**
	 * How many times a row was saved, so a test can say a write happened once.
	 *
	 * @var int
	 */
	public int $writes = 0;

	/**
	 * Seed one row.
	 *
	 * @param string               $schema The schema slug.
	 * @param string               $uuid   The row's uuid.
	 * @param array<string, mixed> $row    The row.
	 *
	 * @return void
	 */
	public function seed(string $schema, string $uuid, array $row): void {
		$row['id'] = $uuid;
		$this->rows[$schema][$uuid] = $row;
	}//end seed()

	/**
	 * One stored row as the test sees it.
	 *
	 * @param string $schema The schema slug.
	 * @param string $uuid   The row's uuid.
	 *
	 * @return array<string, mixed> The row, or [].
	 */
	public function row(string $schema, string $uuid): array {
		return ($this->rows[$schema][$uuid] ?? []);
	}//end row()

	/**
	 * Every stored row of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function all(string $schema): array {
		return array_values($this->rows[$schema] ?? []);
	}//end all()

	/**
	 * Find one object, as ObjectService::find() does.
	 *
	 * @param int|string  $id       The uuid.
	 * @param mixed       $_extend  Ignored.
	 * @param bool        $files    Ignored.
	 * @param int|string  $register Ignored.
	 * @param int|string  $schema   The schema slug.
	 *
	 * @return array<string, mixed>|null The row, or null.
	 */
	public function find(
		int|string $id,
		mixed $_extend = null,
		bool $files = false,
		int|string $register = '',
		int|string $schema = '',
	): ?array {
		return ($this->rows[(string)$schema][(string)$id] ?? null);
	}//end find()

	/**
	 * Search by slug, as ObjectService::searchObjectsBySlug() does.
	 *
	 * Only equality filters, which is all the handover path uses, plus the
	 * `_limit` key it passes and this store ignores.
	 *
	 * @param string               $register Ignored.
	 * @param string               $schema   The schema slug.
	 * @param array<string, mixed> $filters  Equality filters.
	 *
	 * @return array<int, array<string, mixed>> The matching rows.
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
		$matches = [];
		foreach (($this->rows[$schema] ?? []) as $row) {
			if ($this->matches(row: $row, filters: $filters) === true) {
				$matches[] = $row;
			}
		}

		return $matches;
	}//end searchObjectsBySlug()

	/**
	 * Store one object, as ObjectService::saveObject() does.
	 *
	 * @param array<string, mixed> $object   The row.
	 * @param int|string           $register Ignored.
	 * @param int|string           $schema   The schema slug.
	 * @param string|null          $uuid     The uuid to update, or null to create.
	 *
	 * @return array<string, mixed> The stored row.
	 */
	public function saveObject(
		array $object,
		int|string $register = '',
		int|string $schema = '',
		?string $uuid = null,
	): array {
		$id = ($uuid ?? ('generated-' . (count($this->rows[(string)$schema] ?? []) + 1)));
		$object['id'] = $id;
		$this->rows[(string)$schema][$id] = $object;
		$this->writes += 1;

		return $object;
	}//end saveObject()

	/**
	 * Remove one object, as ObjectService::deleteObject() does.
	 *
	 * @param int|string  $register Ignored.
	 * @param int|string  $schema   The schema slug.
	 * @param string      $uuid     The uuid.
	 *
	 * @return bool True when a row was removed.
	 */
	public function deleteObject(int|string $register = '', int|string $schema = '', string $uuid = ''): bool {
		if (isset($this->rows[(string)$schema][$uuid]) === false) {
			return false;
		}

		unset($this->rows[(string)$schema][$uuid]);

		return true;
	}//end deleteObject()

	/**
	 * Whether a row satisfies every equality filter.
	 *
	 * @param array<string, mixed> $row     The row.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return bool True when it matches.
	 */
	private function matches(array $row, array $filters): bool {
		foreach ($filters as $key => $value) {
			if (str_starts_with((string)$key, '_') === true || $key === '@self') {
				continue;
			}

			if (($row[$key] ?? null) !== $value) {
				return false;
			}
		}

		return true;
	}//end matches()
}//end class
