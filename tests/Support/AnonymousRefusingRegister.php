<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * An in-memory OpenRegister that refuses a write from nobody, the way the real
 * one does.
 *
 * 🔑 A BACKGROUND JOB HAS NO SESSION. OpenRegister reads the acting user off the
 * Nextcloud session, finds none, and answers "User 'Anonymous' does not have
 * permission to 'update' objects". Every job that wrote that way logged the
 * refusal and moved on, so the write never landed. This store asks the same
 * question at the same seam: whoever is signed in when the write arrives is
 * the writer, and nobody is a refusal. A write with `_rbac: false` is recorded
 * as a bypass, so a test can assert that no job dodges the check either.
 *
 * Signatures follow tests/Stubs/Contract/ObjectServiceInterface.php.
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

use Closure;
use RuntimeException;

/**
 * An object store that knows who is writing.
 */
class AnonymousRefusingRegister {

	/**
	 * Every stored row, keyed by schema and then by id.
	 *
	 * @var array<string, array<string, array<string, mixed>>>
	 */
	public array $rows = [];

	/**
	 * Every write that landed: [actor, schema, id, object].
	 *
	 * @var array<int, array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
	 */
	public array $writes = [];

	/**
	 * Every refusal, as OpenRegister words it.
	 *
	 * @var array<int, string>
	 */
	public array $refusals = [];

	/**
	 * Every write that asked to skip the permission check.
	 *
	 * @var array<int, string>
	 */
	public array $bypasses = [];

	/**
	 * Constructor.
	 *
	 * @param Closure(): ?string $actor Answers the uid signed in right now, or null.
	 */
	public function __construct(private readonly Closure $actor) {
	}//end __construct()

	/**
	 * Put a row in the store without writing it.
	 *
	 * @param string               $schema The schema id or slug.
	 * @param string               $id     The row id.
	 * @param array<string, mixed> $row    The row.
	 *
	 * @return void
	 */
	public function seed(string $schema, string $id, array $row): void {
		$row['id'] = $id;
		$row['uuid'] = $id;
		$this->rows[$schema][$id] = $row;
	}//end seed()

	/**
	 * One stored row.
	 *
	 * @param string $schema The schema.
	 * @param string $id     The id.
	 *
	 * @return array<string, mixed> The row, empty when absent.
	 */
	public function row(string $schema, string $id): array {
		return ($this->rows[$schema][$id] ?? []);
	}//end row()

	/**
	 * The schemas a write landed on, each once.
	 *
	 * @return array<int, string> The schemas.
	 */
	public function writtenSchemas(): array {
		return array_values(array_unique(array_map(static fn (array $write): string => $write[1], $this->writes)));
	}//end writtenSchemas()

	/**
	 * The accounts a write landed as, each once.
	 *
	 * @return array<int, string> The uids.
	 */
	public function writers(): array {
		return array_values(array_unique(array_map(static fn (array $write): string => $write[0], $this->writes)));
	}//end writers()

	/**
	 * Find one row.
	 *
	 * @param int|string      $id            The id.
	 * @param array|null      $_extend       Ignored.
	 * @param bool            $files         Ignored.
	 * @param string|int|null $register      Ignored.
	 * @param string|int|null $schema        The schema.
	 * @param bool            $_rbac         Ignored.
	 * @param bool            $_multitenancy Ignored.
	 *
	 * @return array<string, mixed>|null The row.
	 */
	public function find(
		int|string $id,
		?array $_extend = [],
		bool $files = false,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): ?array {
		if ($schema === null || $schema === '') {
			foreach ($this->rows as $rows) {
				if (isset($rows[(string)$id]) === true) {
					return $rows[(string)$id];
				}
			}

			return null;
		}

		return ($this->rows[(string)$schema][(string)$id] ?? null);
	}//end find()

	/**
	 * Search by the `@self` register and schema.
	 *
	 * @param array<string, mixed> $query         The query.
	 * @param bool                 $_rbac         Ignored.
	 * @param bool                 $_multitenancy Ignored.
	 *
	 * @return array<int, array<string, mixed>> The matches.
	 */
	public function searchObjects(array $query = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$schema = (string)($query['@self']['schema'] ?? ($query['schema'] ?? ''));
		return $this->matching(schema: $schema, filters: $query);
	}//end searchObjects()

	/**
	 * Search by slugs.
	 *
	 * @param string               $registerSlug  Ignored.
	 * @param string               $schemaSlug    The schema.
	 * @param array<string, mixed> $filters       The filters.
	 * @param bool                 $_rbac         Ignored.
	 * @param bool                 $_multitenancy Ignored.
	 *
	 * @return array<int, array<string, mixed>> The matches.
	 */
	public function searchObjectsBySlug(
		string $registerSlug,
		string $schemaSlug,
		array $filters = [],
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		return $this->matching(schema: $schemaSlug, filters: $filters);
	}//end searchObjectsBySlug()

	/**
	 * The legacy list call.
	 *
	 * @param array<string, mixed> $config        The config, with `filters`.
	 * @param bool                 $_rbac         Ignored.
	 * @param bool                 $_multitenancy Ignored.
	 *
	 * @return array<int, array<string, mixed>> The matches.
	 */
	public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
		$filters = (array)($config['filters'] ?? []);
		$schema = (string)($filters['schema'] ?? '');
		unset($filters['schema'], $filters['register']);

		return $this->matching(schema: $schema, filters: $filters);
	}//end findAll()

	/**
	 * Save a whole row: a uuid replaces what was stored, as OpenRegister does.
	 *
	 * @param array<string, mixed> $object        The row.
	 * @param array|null           $extend        Ignored.
	 * @param string|int|null      $register      Ignored.
	 * @param string|int|null      $schema        The schema.
	 * @param string|null          $uuid          The id to replace.
	 * @param bool                 $_rbac         False is recorded as a bypass.
	 * @param bool                 $_multitenancy Ignored.
	 *
	 * @return array<string, mixed> The stored row.
	 */
	public function saveObject(
		array $object,
		?array $extend = [],
		string|int|null $register = null,
		string|int|null $schema = null,
		?string $uuid = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		$schema = (string)$schema;
		$id = (string)($uuid ?? ($object['id'] ?? ($object['uuid'] ?? '')));
		$action = 'update';
		if ($id === '' || isset($this->rows[$schema][$id]) === false) {
			$action = 'create';
		}

		if ($id === '') {
			$id = $schema.'-'.(count($this->rows[$schema] ?? []) + 1);
		}

		$this->admit(action: $action, schema: $schema, rbac: $_rbac);

		$object['id'] = $id;
		$object['uuid'] = $id;
		$this->rows[$schema][$id] = $object;
		$this->writes[] = [(string)($this->actor)(), $schema, $id, $object];

		return $object;
	}//end saveObject()

	/**
	 * Merge fields into a stored row.
	 *
	 * @param string               $objectId      The id.
	 * @param array<string, mixed> $data          The fields.
	 * @param string|int|null      $register      Ignored.
	 * @param string|int|null      $schema        The schema.
	 * @param bool                 $_rbac         False is recorded as a bypass.
	 * @param bool                 $_multitenancy Ignored.
	 *
	 * @return array<string, mixed> The stored row.
	 */
	public function patchObject(
		string $objectId,
		array $data,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): array {
		$schema = (string)$schema;
		$this->admit(action: 'update', schema: $schema, rbac: $_rbac);
		if (isset($this->rows[$schema][$objectId]) === false) {
			throw new RuntimeException('Object '.$objectId.' not found');
		}

		$merged = array_merge($this->rows[$schema][$objectId], $data);
		$this->rows[$schema][$objectId] = $merged;
		$this->writes[] = [(string)($this->actor)(), $schema, $objectId, $merged];

		return $merged;
	}//end patchObject()

	/**
	 * Delete a row.
	 *
	 * @param string          $uuid          The id.
	 * @param string|int|null $register      Ignored.
	 * @param string|int|null $schema        The schema.
	 * @param bool            $_rbac         False is recorded as a bypass.
	 * @param bool            $_multitenancy Ignored.
	 *
	 * @return bool True when it existed.
	 */
	public function deleteObject(
		string $uuid,
		string|int|null $register = null,
		string|int|null $schema = null,
		bool $_rbac = true,
		bool $_multitenancy = true,
	): bool {
		$schema = (string)$schema;
		$this->admit(action: 'delete', schema: $schema, rbac: $_rbac);
		$found = isset($this->rows[$schema][$uuid]);
		unset($this->rows[$schema][$uuid]);
		$this->writes[] = [(string)($this->actor)(), $schema, $uuid, []];

		return $found;
	}//end deleteObject()

	/**
	 * Refuse a write from nobody, and note one that skips the check.
	 *
	 * @param string $action The CRUD action.
	 * @param string $schema The schema.
	 * @param bool   $rbac   Whether the caller kept the check on.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When nobody is signed in.
	 */
	private function admit(string $action, string $schema, bool $rbac): void {
		if ($rbac === false) {
			$this->bypasses[] = $action.' '.$schema;
		}

		if (($this->actor)() === null) {
			$message = "User 'Anonymous' does not have permission to '".$action."' objects in schema '".$schema."'";
			$this->refusals[] = $message;
			throw new RuntimeException($message);
		}
	}//end admit()

	/**
	 * The rows of one schema that satisfy the equality filters.
	 *
	 * A list value is an IN, and the comparison is loose because a filter
	 * says `0` where the row stores `false`.
	 *
	 * @param string               $schema  The schema.
	 * @param array<string, mixed> $filters The filters.
	 *
	 * @return array<int, array<string, mixed>> The matches.
	 */
	private function matching(string $schema, array $filters): array {
		$matches = [];
		foreach (($this->rows[$schema] ?? []) as $row) {
			$keep = true;
			foreach ($filters as $key => $value) {
				if (str_starts_with((string)$key, '_') === true || $key === '@self') {
					continue;
				}

				$stored = ($row[$key] ?? null);
				if (is_array($value) === true) {
					$keep = in_array($stored, $value, false);
				} else {
					$keep = ($stored == $value);
				}

				if ($keep === false) {
					break;
				}
			}

			if ($keep === true) {
				$matches[] = $row;
			}
		}//end foreach

		return $matches;
	}//end matching()
}//end class
