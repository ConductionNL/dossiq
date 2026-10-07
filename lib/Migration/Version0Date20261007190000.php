<?php

/**
 * Dossiq migration: the term notice ledger.
 *
 * One row per term notice that was claimed, sent or refused. The unique index
 * on `notice_key` is the whole point: it is what refuses a second send of the
 * same notice when two triggers race (see TermNoticeLedger).
 *
 * @category Migration
 * @package  OCA\Dossiq\Migration
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
 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071
 */

declare(strict_types=1);

namespace OCA\Dossiq\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Creates `dossiq_term_notices`.
 *
 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071
 */
class Version0Date20261007190000 extends SimpleMigrationStep {

	/**
	 * Create the ledger table when it is not there.
	 *
	 * @param IOutput $output        The output.
	 * @param Closure $schemaClosure Returns the schema.
	 * @param array   $options       The options.
	 *
	 * @return ISchemaWrapper|null The changed schema, or null for no change.
	 *
	 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) The signature is SimpleMigrationStep's.
	 */
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('dossiq_term_notices') === true) {
			return null;
		}

		$table = $schema->createTable('dossiq_term_notices');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
		$table->addColumn('notice_key', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('template', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
		$table->addColumn('instance_ref', Types::STRING, ['notnull' => true, 'length' => 64, 'default' => '']);
		$table->addColumn('outcome', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('updated_at', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['notice_key'], 'dossiq_termnotice_key');
		$table->addIndex(['instance_ref'], 'dossiq_termnotice_inst');

		return $schema;
	}//end changeSchema()
}//end class
