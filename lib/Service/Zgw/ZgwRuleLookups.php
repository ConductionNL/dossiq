<?php

/**
 * What the ZGW business rules read from the store
 *
 * Finds an object by a field or by its uuid, and answers whether a pair of
 * values is taken, in the register of the request. The rules class sets the
 * request context once; every collaborator that holds this object reads in it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\Db\DoesNotExistException;
use RuntimeException;

/**
 * Store lookups of the ZGW business rules, in the request context.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ZgwRuleLookups {
	/**
	 * The OpenRegister ObjectService (set per request).
	 *
	 * @var object|null
	 */
	private ?object $objectService = null;

	/**
	 * The mapping config (set per request).
	 *
	 * @var array|null
	 */
	private ?array $mappingConfig = null;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Set the per-request services for cross-resource lookups.
	 *
	 * @param object|null $objectService The OpenRegister ObjectService
	 * @param array|null $mappingConfig The mapping config
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function setContext(?object $objectService, ?array $mappingConfig): void {
		$this->objectService = $objectService;
		$this->mappingConfig = $mappingConfig;
	}//end setContext()

	/**
	 * The object service of this request, or null without one.
	 *
	 * @return object|null
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function objectService(): ?object {
		return $this->objectService;
	}//end objectService()

	/**
	 * The register the request's mapping stores in, as the mapping holds it, or '' without one.
	 *
	 * @return mixed
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function register(): mixed {
		return ($this->mappingConfig['sourceRegister'] ?? '');
	}//end register()

	/**
	 * Find an object UUID by a field value (omschrijving/identificatie).
	 *
	 * @param string $register The OpenRegister register ID
	 * @param string $schema The OpenRegister schema ID
	 * @param string $field The field to search by
	 * @param string $value The value to search for
	 *
	 * @return string|null The object UUID, or null when none carries the value or there is no store
	 *
	 * @throws LookupNotCompleted When the search fails: a type that could not be read does not read as absent.
	 *
	 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
	 */
	public function findObjectByField(
		string $register,
		string $schema,
		string $field,
		string $value,
	): ?string {
		if ($this->objectService === null) {
			return null;
		}

		try {
			$query = $this->objectService->buildSearchQuery(
				requestParams: [$field => $value, '_limit' => 1],
				register: $register,
				schema: $schema
			);
			$result = $this->objectService->searchObjectsPaginated(query: $query);

			$results = $result['results'] ?? [];
			if (empty($results) === true) {
				return null;
			}

			$obj = $results[0];
			$data = $obj;
			if (is_array($obj) === false) {
				$data = $obj->jsonSerialize();
			}

			return $data['id'] ?? ($data['@self']['id'] ?? null);
		} catch (\Throwable $e) {
			throw new LookupNotCompleted(lookup: "reference {$field} \"{$value}\"", cause: $e);
		}//end try
	}//end findObjectByField()

	/**
	 * Find all objects matching a field value.
	 *
	 * Without a store in the request context nothing is looked up and the list
	 * is empty. A search that fails throws.
	 *
	 * @param string $register The register to search in
	 * @param string $schema The schema to search in
	 * @param string $field The field to match on
	 * @param string $value The field value to search for
	 *
	 * @return array<string> Array of matching object UUIDs
	 *
	 * @throws LookupNotCompleted When the search fails.
	 *
	 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
	 */
	public function findAllObjectsByField(
		string $register,
		string $schema,
		string $field,
		string $value,
	): array {
		if ($this->objectService === null) {
			return [];
		}

		try {
			return $this->idsByField(register: $register, schema: $schema, field: $field, value: $value);
		} catch (\Throwable $e) {
			throw new LookupNotCompleted(lookup: "reference {$field} \"{$value}\"", cause: $e);
		}
	}//end findAllObjectsByField()

	/**
	 * The ids of the objects that carry a field value. A search that cannot run throws.
	 *
	 * An empty list means the search ran and found none.
	 *
	 * @param string $register The register to search in
	 * @param string $schema The schema to search in
	 * @param string $field The field to match on
	 * @param string $value The field value to search for
	 *
	 * @return array<string> The ids of the matching objects
	 *
	 * @throws \Throwable When the store cannot be searched.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function idsByField(
		string $register,
		string $schema,
		string $field,
		string $value,
	): array {
		if ($this->objectService === null) {
			throw new RuntimeException('No object service in the request context');
		}

		$query = $this->objectService->buildSearchQuery(
			requestParams: [$field => $value, '_limit' => 100],
			register: $register,
			schema: $schema
		);
		$result = $this->objectService->searchObjectsPaginated(query: $query);

		$ids = [];
		foreach (($result['results'] ?? []) as $obj) {
			$data = $obj;
			if (is_array($obj) === false) {
				$data = $obj->jsonSerialize();
			}

			$id = $data['id'] ?? ($data['@self']['id'] ?? null);
			if ($id !== null) {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end idsByField()

	/**
	 * Look up an object in OpenRegister by UUID and schema key.
	 *
	 * @param string $uuid The object UUID
	 * @param string $schemaKey The settings config key for the schema
	 *
	 * Nothing is looked up, and the answer is null, without a store, a register
	 * in the mapping or a schema setting for the key. An object the store does
	 * not hold, or does not show this caller, is null too.
	 *
	 * @return array|null The object data, or null when there is none to read
	 *
	 * @throws LookupNotCompleted When the read fails for any other reason.
	 *
	 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
	 */
	public function findBySchemaKey(string $uuid, string $schemaKey): ?array {
		if ($this->objectService === null) {
			return null;
		}

		$register = $this->mappingConfig['sourceRegister'] ?? '';
		$schema = $this->settingsService->getConfigValue(key: $schemaKey);

		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		try {
			$obj = $this->objectService->find(
				id: $uuid,
				register: $register,
				schema: $schema
			);
		} catch (DoesNotExistException $e) {
			return null;
		} catch (\Throwable $e) {
			if ($this->isRefusedRead(error: $e) === true) {
				return null;
			}

			throw new LookupNotCompleted(lookup: "{$schemaKey} object {$uuid}", cause: $e);
		}//end try

		if ($obj === null || is_array($obj) === true) {
			return $obj;
		}

		return $obj->jsonSerialize();
	}//end findBySchemaKey()

	/**
	 * Whether a read failed because the caller may not see the object.
	 *
	 * OpenRegister refuses a read with its NotAuthorizedException (code 403), and
	 * hides an object of another organisation as not found. A rule treats both
	 * as an object it cannot use, as it did before; only a read that could not
	 * run is a reason to refuse the request.
	 *
	 * @param \Throwable $error The failure.
	 *
	 * @return bool
	 */
	private function isRefusedRead(\Throwable $error): bool {
		return is_a($error, 'OCA\\OpenRegister\\Exception\\NotAuthorizedException');
	}//end isRefusedRead()

	/**
	 * Whether a stored object already carries this pair of values (identificatie + organisatie).
	 *
	 * Nothing is looked up, and the pair counts as free, without a first value, an
	 * object service, or a register and schema in the mapping. A search that fails
	 * throws: a duplicate nobody could look for is not a free pair.
	 *
	 * @param string $field1Value First field value (e.g. identificatie)
	 * @param string $field1Search OpenRegister field name to search
	 * @param string $field2Value Second field value (e.g. organisatie)
	 * @param string $field2Search OpenRegister field name to search
	 *
	 * @return bool True when the pair is taken
	 *
	 * @throws LookupNotCompleted When the search fails.
	 *
	 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
	 */
	public function fieldPairExists(
		string $field1Value,
		string $field1Search,
		string $field2Value,
		string $field2Search,
	): bool {
		if ($field1Value === '' || $this->objectService === null) {
			return false;
		}

		$register = $this->mappingConfig['sourceRegister'] ?? '';
		$schema = $this->mappingConfig['sourceSchema'] ?? '';
		if (empty($register) === true || empty($schema) === true) {
			return false;
		}

		try {
			// Build query directly to avoid buildSearchQuery's underscore-splitting
			// which breaks camelCase field names like sourceOrganisation.
			// Search only by field1 (identifier) because OpenRegister may store
			// numeric strings (e.g. "000000000") as integers, which breaks
			// exact-match search for field2 (sourceOrganisation).
			$query = [
				'@self' => [
					'register' => (int)$register,
					'schema' => (int)$schema,
				],
				$field1Search => $field1Value,
			];

			$result = $this->objectService->searchObjectsPaginated(
				query: $query,
				_rbac: false,
				_multitenancy: false
			);

			// Post-filter results by field2 value in memory, comparing both
			// string and numeric forms to handle integer coercion by OpenRegister.
			// OpenRegister may store numeric-looking strings (e.g. "000000000")
			// as integer 0, which the magic mapper may serialize to empty string.
			// When the stored value is empty but field2 was provided, we still
			// count it as a match (conservative: assume coercion happened).
			$matchCount = 0;
			foreach (($result['results'] ?? []) as $obj) {
				if ($this->storedValueMatches(row: $obj, field: $field2Search, value: $field2Value) === true) {
					$matchCount++;
				}
			}

			return ($matchCount > 0);
		} catch (\Throwable $e) {
			throw new LookupNotCompleted(lookup: "uniqueness of {$field1Search} \"{$field1Value}\"", cause: $e);
		}//end try
	}//end fieldPairExists()

	/**
	 * Whether one hit carries the same second-field value (uniqueness check).
	 *
	 * OpenRegister may store numeric-looking strings (e.g. "000000000") as
	 * integer 0, which the magic mapper may serialise to an empty string. So
	 * an empty or "0" stored value counts as a match (conservative: assume
	 * coercion happened), and no second value matches every hit.
	 *
	 * @param mixed $row The hit, an array or an entity.
	 * @param string $field The OpenRegister field of the second value.
	 * @param string $value The second value.
	 *
	 * @return bool
	 */
	private function storedValueMatches(mixed $row, string $field, string $value): bool {
		$data = $row;
		if (is_array($row) === false) {
			$data = $row->jsonSerialize();
		}

		$storedStr = (string)($data[$field] ?? null);

		return ($value === '')
			|| ($storedStr === $value)
			|| ($storedStr === '')
			|| ($storedStr === '0' && preg_match('/^0+$/', $value) === 1);
	}//end storedValueMatches()
}//end class
