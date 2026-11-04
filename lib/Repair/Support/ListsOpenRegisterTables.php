<?php

/**
 * Lists OpenRegister's tables through information_schema.
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

use OCP\DB\IPreparedStatement;

/**
 * The executed query for every table carrying the `openregister_table_` marker.
 *
 * The using class has a `$db` (IDBConnection) property.
 *
 * @spec openspec/specs/case-management/spec.md
 */
trait ListsOpenRegisterTables {
	/**
	 * Run the table listing; the caller reads the rows and handles a failure.
	 *
	 * Table discovery goes through information_schema, NOT IDBConnection.
	 * OCP\IDBConnection exposes neither getSchema() nor getPrefix(); calling
	 * either is a runtime fatal that `php -l` and phpcs both report as clean.
	 * The match is on the `openregister_table_` MARKER rather than a computed
	 * prefix: getQueryBuilder()->getTableName('') yields the literal
	 * `*PREFIX*` placeholder, which a raw information_schema string never
	 * resolves, so a LIKE built from it matches zero tables.
	 *
	 * @return IPreparedStatement The executed statement.
	 *
	 * @throws \Throwable When the listing cannot run.
	 */
	private function openRegisterTableListing(): IPreparedStatement {
		$stmt = $this->db->prepare(
			'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
		);
		$stmt->bindValue('pattern', '%openregister\_table\_%');
		$stmt->execute();

		return $stmt;
	}//end openRegisterTableListing()
}//end trait
