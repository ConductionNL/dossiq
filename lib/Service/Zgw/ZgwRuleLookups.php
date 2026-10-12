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
use Psr\Log\LoggerInterface;

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
	 * @param LoggerInterface $logger The logger
	 * @param SettingsService $settingsService The settings service
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
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
	 * @return string|null The object UUID, or null if not found
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function findObjectByField(
		string $register,
		string $schema,
		string $field,
		string $value,
	): ?string {
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
			$this->logger->warning(
				'Reference resolution failed: ' . $e->getMessage(),
				['field' => $field, 'value' => $value]
			);
			return null;
		}//end try
	}//end findObjectByField()

	/**
	 * Find all objects matching a field value.
	 *
	 * @param string $register The register to search in
	 * @param string $schema The schema to search in
	 * @param string $field The field to match on
	 * @param string $value The field value to search for
	 *
	 * @return array<string> Array of matching object UUIDs
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function findAllObjectsByField(
		string $register,
		string $schema,
		string $field,
		string $value,
	): array {
		try {
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
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Reference resolution failed: ' . $e->getMessage(),
				['field' => $field, 'value' => $value]
			);
			return [];
		}//end try
	}//end findAllObjectsByField()

	/**
	 * Look up an object in OpenRegister by UUID and schema key.
	 *
	 * @param string $uuid The object UUID
	 * @param string $schemaKey The settings config key for the schema
	 *
	 * @return array|null The object data, or null on failure
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
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
			if (is_array($obj) === true) {
				return $obj;
			}

			return $obj->jsonSerialize();
		} catch (\Throwable $e) {
			return null;
		}
	}//end findBySchemaKey()

	/**
	 * Whether a stored object already carries this pair of values (identificatie + organisatie).
	 *
	 * Nothing is looked up, and the pair counts as free, without a first value, an
	 * object service, or a register and schema in the mapping. A search that fails
	 * is logged and counts as free too.
	 *
	 * @param string $field1Value First field value (e.g. identificatie)
	 * @param string $field1Search OpenRegister field name to search
	 * @param string $field2Value Second field value (e.g. organisatie)
	 * @param string $field2Search OpenRegister field name to search
	 *
	 * @return bool True when the pair is taken
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
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
			$this->logger->warning(
				'Uniqueness check failed: ' . $e->getMessage()
			);
		}//end try

		return false;
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
