<?php

/**
 * An in-memory OpenRegister object service for the sociaal domein schemas.
 *
 * 🔴 IT SPEAKS THE SLUG PATH, BECAUSE THAT IS WHAT THE CODE UNDER TEST USES.
 * `SearchesObjects` sends a NON-NUMERIC register or schema to
 * `searchObjectsBySlug()` and a numeric one into the `@self` block of
 * `searchObjects()`, and the sociaal domein schemas are addressed by slug. A
 * fake that only implemented `searchObjects()` would answer nothing, every test
 * would read an empty list, and a service that never found anything would look
 * exactly like one that correctly found nothing.
 *
 * 🔑 `saveObject()` ASSIGNS AN ID THE WAY OPENREGISTER DOES: a write with no
 * uuid creates, a write with one replaces. A fake that always created would let
 * a test pass on a service that writes a second goal every time it closes one.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-jeugdwet/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\SociaalDomein;

/**
 * A store of rows, keyed by schema slug then by uuid.
 */
class FakeSociaalDomeinObjects {

	/**
	 * The rows, by schema slug then id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $store = [];

	/**
	 * Whether every read should fail, for the refusal tests.
	 *
	 * @var boolean
	 */
	public bool $broken = false;

	/**
	 * The id sequence.
	 *
	 * @var integer
	 */
	private int $seq = 0;

	/**
	 * Put a row in the store directly.
	 *
	 * @param string               $schema The schema slug.
	 * @param array<string, mixed> $row    The row.
	 *
	 * @return array<string, mixed> The stored row.
	 */
	public function seed(string $schema, array $row): array {
		if (trim((string)($row['id'] ?? '')) === '') {
			$this->seq++;
			$row['id'] = $schema . '-' . $this->seq;
		}

		$this->store[$schema][(string)$row['id']] = $row;

		return $row;
	}//end seed()

	/**
	 * The rows of one schema.
	 *
	 * @param string $schema The schema slug.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function rowsOf(string $schema): array {
		return array_values($this->store[$schema] ?? []);
	}//end rowsOf()

	/**
	 * Search one schema by slug, on bare filter keys.
	 *
	 * @param string               $registerSlug The register.
	 * @param string               $schemaSlug   The schema.
	 * @param array<string, mixed> $filters      The filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	public function searchObjectsBySlug(string $registerSlug, string $schemaSlug, array $filters = []): array {
		if ($this->broken === true) {
			throw new \RuntimeException('the store is unreachable');
		}

		unset($filters['_limit'], $filters['_offset'], $filters['@self']);

		$rows = [];
		foreach (($this->store[$schemaSlug] ?? []) as $row) {
			$matches = true;
			foreach ($filters as $key => $value) {
				// An UNDECLARED key matches nothing, which is what OpenRegister
				// does and is exactly the trap the per-domain bsn key exists
				// for: filtering jeugdwetZaak on `bsn` must read as "no case",
				// not as "every case".
				if ((string)($row[$key] ?? '') !== (string)$value) {
					$matches = false;
					break;
				}
			}

			if ($matches === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end searchObjectsBySlug()

	/**
	 * Read one object by id.
	 *
	 * @param string $id       The id.
	 * @param mixed  $register The register.
	 * @param mixed  $schema   The schema.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	public function find(string $id, $register = null, $schema = null): ?array {
		if ($this->broken === true) {
			throw new \RuntimeException('the store is unreachable');
		}

		return ($this->store[(string)$schema][$id] ?? null);
	}//end find()

	/**
	 * Create or replace one object.
	 *
	 * @param array<string, mixed> $object   The object.
	 * @param mixed                $register The register.
	 * @param mixed                $schema   The schema.
	 * @param string|null          $uuid     The uuid, when replacing.
	 *
	 * @return array<string, mixed> The stored object.
	 */
	public function saveObject(array $object, $register = null, $schema = null, ?string $uuid = null): array {
		if ($this->broken === true) {
			throw new \RuntimeException('the store is unreachable');
		}

		$schema = (string)$schema;
		$id = (($uuid !== null && trim($uuid) !== '') ? $uuid : trim((string)($object['id'] ?? '')));
		if ($id === '') {
			$this->seq++;
			$id = $schema . '-' . $this->seq;
		}

		$object['id'] = $id;
		$this->store[$schema][$id] = $object;

		return $object;
	}//end saveObject()
}//end class
