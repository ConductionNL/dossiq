<?php

/**
 * Reads the OpenRegister shard tables of dossiq's registers, for repair steps that rewrite them.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Support
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Support;

use OCP\DB\Exception;

/**
 * The shared reads and writes of the shard repairs.
 *
 * The using class has `$db` (IDBConnection) and `$logger` (LoggerInterface)
 * properties, and a `LOG_NAME` constant that prefixes every warning.
 *
 * @spec openspec/specs/case-management/spec.md
 */
trait ReadsRegisterShards {
	/**
	 * The ids of the registers whose slug starts with the prefix, or none when they cannot be read.
	 *
	 * @param string $warning What the warning says after the step's name.
	 *
	 * @return array<int, mixed>
	 */
	private function registerIdsOrNone(string $warning): array {
		try {
			return $this->db->executeQuery(
				'SELECT id FROM `*PREFIX*openregister_registers` WHERE slug LIKE ?',
				[self::REGISTER_SLUG_PREFIX . '%']
			)->fetchAll(\PDO::FETCH_COLUMN);
		} catch (Exception $e) {
			$this->logger->warning(self::LOG_NAME . ': ' . $warning, ['exception' => $e->getMessage()]);
			return [];
		}
	}//end registerIdsOrNone()

	/**
	 * The names of every OpenRegister table, or none when they cannot be listed.
	 *
	 * Table discovery goes through information_schema, NOT IDBConnection.
	 * OCP\IDBConnection exposes neither getSchema() nor getPrefix(); calling
	 * either is a runtime fatal that `php -l` and phpcs both report as clean.
	 * The match is on the `openregister_table_` MARKER rather than a computed
	 * prefix: getQueryBuilder()->getTableName('') yields the literal
	 * `*PREFIX*` placeholder, which a raw information_schema string never
	 * resolves, so a LIKE built from it matches zero tables.
	 *
	 * @return array<int, string>
	 */
	private function openRegisterTableNames(): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
			);
			$stmt->bindValue('pattern', '%openregister\_table\_%');
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(self::LOG_NAME . ': could not list tables; skipping.', ['exception' => $e->getMessage()]);
			return [];
		}

		$names = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['table_name'] ?? '');
			if ($name !== '') {
				$names[] = $name;
			}
		}

		return $names;
	}//end openRegisterTableNames()

	/**
	 * The `openregister_table_<registerId>_` marker of each register.
	 *
	 * @param array<int, mixed> $ids The register ids.
	 *
	 * @return array<int, string>
	 */
	private function shardMarkers(array $ids): array {
		$markers = [];
		foreach ($ids as $id) {
			$markers[] = 'openregister_table_' . ((int)$id) . '_';
		}

		return $markers;
	}//end shardMarkers()

	/**
	 * List the column names of a table.
	 *
	 * Queried from information_schema for the same reason as the tables:
	 * IDBConnection has no getSchema().
	 *
	 * @param string $table Table name.
	 *
	 * @return array<int, string>
	 */
	private function columnsOf(string $table): array {
		try {
			$stmt = $this->db->prepare(
				'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
			);
			$stmt->bindValue('table', $table);
			$stmt->execute();
		} catch (\Throwable $e) {
			$this->logger->warning(
				self::LOG_NAME . ': could not read columns; skipping table.',
				['table' => $table, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$columns = [];
		while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
			$name = (string)($row['column_name'] ?? '');
			if ($name !== '') {
				$columns[] = $name;
			}
		}

		return $columns;
	}//end columnsOf()

	/**
	 * Execute one DDL/DML statement, logging and swallowing failure.
	 *
	 * A failure must not abort the repair run: the remaining tables are
	 * independent, and an un-migrated column is still readable.
	 *
	 * @param string $sql The statement.
	 *
	 * @return bool Whether it succeeded.
	 */
	private function exec(string $sql): bool {
		try {
			$this->db->executeStatement($sql);
			return true;
		} catch (Exception $e) {
			$this->logger->warning(
				self::LOG_NAME . ': statement failed; leaving the column as it was.',
				['sql' => $sql, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end exec()

	/**
	 * Quote an identifier for the active platform.
	 *
	 * The table names come from information_schema on this same connection
	 * and the columns are class constants, so neither is caller-supplied.
	 *
	 * @param string $identifier Table or column name.
	 *
	 * @return string
	 */
	private function quote(string $identifier): string {
		return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);
	}//end quote()
}//end trait
