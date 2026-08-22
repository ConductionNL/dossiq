<?php

/**
 * Dossiq Tenant Schema Provisioner
 *
 * Wraps the raw PostgreSQL DDL primitives (CREATE SCHEMA, table cloning,
 * DROP SCHEMA) used by the schema-per-tenant provisioning flow.
 *
 * Schema names are pre-validated by `TenantProvisioningService::buildSchemaName()`
 * (UUID-derived, ≤63 chars, identifier-safe) so the DDL bind cannot inject.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenant-zaaksysteem-saas-03-schema-provisioning/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use InvalidArgumentException;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Postgres-native schema-per-tenant primitives.
 *
 * The cloning step copies application table structures (not shared tables —
 * those stay in `public`). Shared tables are the SaaS-control plane:
 * `tenant`, `tenantConfiguration`, `tenantQuota`, `tenantUser`,
 * `tenantMandate`, `tenantBillingEvent`, `tenantOnboardingTask`.
 */
class TenantSchemaProvisioner {
	/**
	 * Maximum PostgreSQL identifier length.
	 */
	public const PG_IDENTIFIER_MAX_LENGTH = 63;

	/**
	 * Application table prefixes whose structure is cloned per tenant.
	 *
	 * @var array<int, string>
	 */
	private const APPLICATION_TABLE_PREFIXES = [
		// FROZEN. OpenRegister derives this physical table prefix from the
		// register SLUG, which stays `procest` across the app-id rename. The
		// tables on disk are named `oc_openregister_table_procest_*`; a renamed
		// prefix here matches nothing, so per-tenant provisioning would clone
		// ZERO application tables and report success — every new tenant would
		// come up with an empty schema.
		'oc_openregister_table_procest_',
	];

	/**
	 * Shared tables that MUST stay in the public schema.
	 *
	 * @var array<int, string>
	 */
	private const SHARED_SCHEMA_SLUGS = [
		'tenant',
		'tenantConfiguration',
		'tenantQuota',
		'tenantUser',
		'tenantMandate',
		'tenantBillingEvent',
		'tenantOnboardingTask',
	];

	/**
	 * Constructor.
	 *
	 * @param IDBConnection $db DB connection.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Create a new schema.
	 *
	 * @param string $name Schema name (already validated).
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the name is invalid.
	 * @throws RuntimeException When the DDL fails.
	 */
	public function createSchema(string $name): void {
		$this->assertSafeIdentifier(name: $name);

		try {
			// The identifier is whitelisted (assertSafeIdentifier); double-quoting
			// it makes Postgres reject any remaining injection attempt.
			$sql = 'CREATE SCHEMA "' . $name . '"';
			$this->db->executeStatement($sql);
		} catch (Throwable $e) {
			throw new RuntimeException('CREATE SCHEMA failed: ' . $e->getMessage(), 0, $e);
		}
	}//end createSchema()

	/**
	 * Clone application table structures from `public` into the tenant schema.
	 *
	 * Uses `CREATE TABLE ... (LIKE source INCLUDING ALL)` so constraints,
	 * defaults, and indexes are preserved. Shared tables are skipped.
	 *
	 * @param string $schemaName Target tenant schema.
	 *
	 * @return array<int, string> Cloned table names.
	 *
	 * @throws RuntimeException On DDL failure.
	 */
	public function cloneApplicationTables(string $schemaName): array {
		$this->assertSafeIdentifier(name: $schemaName);

		$sourceTables = $this->listApplicationTables();
		$cloned = [];

		foreach ($sourceTables as $sourceTable) {
			if ($this->isSharedTable(tableName: $sourceTable) === true) {
				continue;
			}

			$tableName = $this->extractTableName(fullName: $sourceTable);
			try {
				$sql = sprintf(
					'CREATE TABLE "%s"."%s" (LIKE "%s" INCLUDING ALL)',
					$schemaName,
					$tableName,
					$sourceTable
				);
				$this->db->executeStatement($sql);
				$cloned[] = $tableName;
			} catch (Throwable $e) {
				throw new RuntimeException(
					'Failed to clone table ' . $sourceTable . ': ' . $e->getMessage(),
					0,
					$e
				);
			}
		}//end foreach

		$this->logger->info(
			'Dossiq: cloned application tables into tenant schema',
			['schemaName' => $schemaName, 'count' => count($cloned)]
		);

		return $cloned;
	}//end cloneApplicationTables()

	/**
	 * Drop a tenant schema and all its contents. Used by rollback + termination.
	 *
	 * @param string $name Schema name.
	 *
	 * @return void
	 *
	 * @throws RuntimeException On DDL failure.
	 */
	public function dropSchema(string $name): void {
		$this->assertSafeIdentifier(name: $name);

		try {
			$sql = 'DROP SCHEMA IF EXISTS "' . $name . '" CASCADE';
			$this->db->executeStatement($sql);
		} catch (Throwable $e) {
			throw new RuntimeException('DROP SCHEMA failed: ' . $e->getMessage(), 0, $e);
		}
	}//end dropSchema()

	/**
	 * Return whether a schema currently exists. Used by tests + idempotency.
	 *
	 * @param string $name Schema name.
	 *
	 * @return bool True when present.
	 */
	public function schemaExists(string $name): bool {
		$this->assertSafeIdentifier(name: $name);
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('schema_name')
				->from('information_schema.schemata')
				->where($qb->expr()->eq('schema_name', $qb->createNamedParameter($name)));
			$result = $qb->executeQuery();
			$row = $result->fetchOne();
			$result->closeCursor();
			return $row !== false;
		} catch (Throwable $e) {
			$this->logger->info('Dossiq: schemaExists lookup failed', ['name' => $name, 'exception' => $e->getMessage()]);
			return false;
		}
	}//end schemaExists()

	/**
	 * Validate that the identifier is safe to embed in DDL.
	 *
	 * @param string $name Identifier.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When invalid.
	 */
	public function assertSafeIdentifier(string $name): void {
		if ($name === '' || strlen($name) > self::PG_IDENTIFIER_MAX_LENGTH) {
			throw new InvalidArgumentException('Invalid PostgreSQL identifier length: ' . $name);
		}

		if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
			throw new InvalidArgumentException('Invalid PostgreSQL identifier shape: ' . $name);
		}
	}//end assertSafeIdentifier()

	/**
	 * List application tables in the public schema that match one of the prefixes.
	 *
	 * @return array<int, string>
	 */
	private function listApplicationTables(): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('table_name')
				->from('information_schema.tables')
				->where($qb->expr()->eq('table_schema', $qb->createNamedParameter('public')));
			$result = $qb->executeQuery();
			$rows = $result->fetchAll(\PDO::FETCH_ASSOC);
			$result->closeCursor();

			$tables = [];
			foreach ($rows as $row) {
				$name = (string)($row['table_name'] ?? '');
				foreach (self::APPLICATION_TABLE_PREFIXES as $prefix) {
					if (str_starts_with($name, $prefix) === true) {
						$tables[] = $name;
						break;
					}
				}
			}

			return $tables;
		} catch (Throwable $e) {
			$this->logger->info('Dossiq: listApplicationTables failed', ['exception' => $e->getMessage()]);
			return [];
		}//end try
	}//end listApplicationTables()

	/**
	 * Detect shared tables — they remain in the public schema.
	 *
	 * @param string $tableName Table name.
	 *
	 * @return bool True when shared.
	 */
	private function isSharedTable(string $tableName): bool {
		$lower = strtolower($tableName);
		foreach (self::SHARED_SCHEMA_SLUGS as $slug) {
			if (str_contains($lower, '_' . strtolower($slug) . '_') === true || str_ends_with($lower, '_' . strtolower($slug)) === true) {
				return true;
			}
		}

		return false;
	}//end isSharedTable()

	/**
	 * Extract the bare table name (no schema qualifier).
	 *
	 * @param string $fullName Source table name.
	 *
	 * @return string Bare name.
	 */
	private function extractTableName(string $fullName): string {
		$parts = explode('.', $fullName);
		return end($parts);
	}//end extractTableName()
}//end class
