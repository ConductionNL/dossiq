<?php

/**
 * Procest RenameDutchDeadlineColumns Repair Step
 *
 * Moves stored data from the Dutch column names to the English ones the
 * procest register declares.
 *
 * WHY THIS IS NEEDED AT ALL. OpenRegister does not store an object as a JSON
 * blob keyed by property name — each schema property is a real, snake_cased
 * COLUMN in the per-schema shard table `oc_openregister_table_{register}_{schema}`.
 * On schema sync MagicMapper ADDS a column when the snake_cased property name
 * is absent, and it NEVER renames: there is not a single `RENAME COLUMN` in
 * openregister. Its only DROP path removes a camelCase duplicate whose
 * snake_case twin already exists.
 *
 * Renaming `onderwerp` to `subject` in the register therefore leaves the data
 * in `onderwerp` while every read looks at `subject` and finds null. No error,
 * no data loss, and invisible to the suites, which assert against fixtures
 * rather than migrated rows.
 *
 * WHY IT MATCHES TWO REGISTERS, NOT ONE. This install carries BOTH `procest`
 * (1051 rows) and `procest-default` (107 rows). The sibling step in decidesk
 * resolves a single exact slug; doing that here would silently skip 107 rows
 * and report success. The register set is therefore resolved by slug prefix and
 * every match is migrated.
 *
 * WHAT IS EXEMPT. `zaaktype` is a ZGW term: it is the field name in the
 * statutory Zaakgericht Werken wire format that this app both consumes and
 * emits, so it is exempt under the fleet vocabulary rule and is deliberately
 * absent from the map below — even though it is the second most widespread
 * Dutch column here (14 shard tables).
 *
 * COLLISIONS ARE REFUSED, NOT MERGED. `omschrijving` and `beschrijving` both
 * mean `description`. Measured: they do not co-occur in any shard table on this
 * install. A later fragment could introduce a pair, and a silent merge would
 * destroy one of two values, so the step detects two sources targeting one
 * destination in a table, migrates NEITHER, and logs.
 *
 * SAFETY. Non-destructive and idempotent:
 *   - a column is renamed only when the OLD one exists and the NEW one does not;
 *   - where MagicMapper has already added an empty NEW column, the data is
 *     copied across and the old column is LEFT IN PLACE, so the step is
 *     reversible and a re-run is a no-op;
 *   - nothing is deleted, and soft-deleted rows are migrated too — the
 *     back-fill does not filter on `_deleted`, because a restored row must not
 *     come back with a null subject.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category Repair
 * @package  OCA\Procest\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

use OCP\DB\Exception;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Rename procest's Dutch deadline columns to their English equivalents.
 *
 * @spec openspec/specs/termijnbewaking-schemas/spec.md
 */
class RenameDutchDeadlineColumns implements IRepairStep
{
    /**
     * Slug prefix of the registers in scope.
     *
     * Matches `procest` and `procest-default`; both hold live rows.
     *
     * @var string
     */
    private const REGISTER_SLUG_PREFIX = 'procest';

    /**
     * Old snake_case column name => new snake_case column name.
     *
     * Snake_case, not camelCase: MagicMapper stores `endDateActual` as
     * `end_date_actual`, and a camelCase column is exactly what its
     * de-duplication path then drops.
     *
     * `zaaktype` is deliberately ABSENT — see the class docblock.
     *
     * `toelichting` maps to `notes`, NOT `description`: it co-occurs with
     * `omschrijving` elsewhere in the fleet, so they are distinct concepts.
     *
     * @var array<string, string>
     */
    private const COLUMN_MAP = [
        'naam'                => 'name',
        'onderwerp'           => 'subject',
        'omschrijving'        => 'description',
        'beschrijving'        => 'description',
        'toelichting'         => 'notes',
        'motivering'          => 'rationale',
        'afdeling'            => 'department',
        'aantal_verlengingen' => 'extension_count',
        'einddatum_actueel'   => 'end_date_actual',
        'pauze_deadline'      => 'pause_deadline',
    ];

    /**
     * Constructor.
     *
     * @param IDBConnection   $db     Database connection.
     * @param LoggerInterface $logger Logger.
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Human-readable step name.
     *
     * @return string
     *
     * @spec openspec/specs/termijnbewaking-schemas/spec.md
     */
    public function getName(): string
    {
        return 'Move procest data from the Dutch columns to the English ones';

    }//end getName()

    /**
     * Run the column migration across every procest shard table.
     *
     * @param IOutput $output Repair output.
     *
     * @return void
     *
     * @spec openspec/specs/termijnbewaking-schemas/spec.md
     */
    public function run(IOutput $output): void
    {
        $tables = $this->shardTables();
        if ($tables === []) {
            $output->info('RenameDutchDeadlineColumns: no procest shard tables on this install; nothing to do.');
            return;
        }

        $renamed = 0;
        $copied  = 0;
        $refused = 0;

        foreach ($tables as $table) {
            $columns = $this->columnsOf(table: $table);
            $qTable  = $this->quote(identifier: $table);

            foreach (self::COLUMN_MAP as $old => $new) {
                if (in_array($old, $columns, true) === false) {
                    // Already migrated, or this schema never had the property.
                    continue;
                }

                if ($this->hasCollision(table: $table, columns: $columns, target: $new) === true) {
                    $refused++;
                    continue;
                }

                $qOld = $this->quote(identifier: $old);
                $qNew = $this->quote(identifier: $new);

                if (in_array($new, $columns, true) === false) {
                    $sql = 'ALTER TABLE '.$qTable.' RENAME COLUMN '.$qOld.' TO '.$qNew;
                    if ($this->exec(sql: $sql) === true) {
                        $renamed++;
                    }

                    continue;
                }

                // The mapper already added an empty English column: back-fill and
                // leave the Dutch one, so this stays reversible.
                $sql = 'UPDATE '.$qTable.' SET '.$qNew.' = '.$qOld
                    .' WHERE '.$qNew.' IS NULL AND '.$qOld.' IS NOT NULL';
                if ($this->exec(sql: $sql) === true) {
                    $copied++;
                }
            }//end foreach
        }//end foreach

        $output->info(
            'RenameDutchDeadlineColumns: '.$renamed.' column(s) renamed, '
            .$copied.' back-filled, '.$refused.' refused for ambiguity, across '
            .count($tables).' procest shard table(s).'
        );

    }//end run()

    /**
     * Whether two Dutch columns in this table both target one English name.
     *
     * @param string             $table   Table name.
     * @param array<int, string> $columns Its column names.
     * @param string             $target  The English destination name.
     *
     * @return bool True when the rename is ambiguous and must be skipped.
     */
    private function hasCollision(string $table, array $columns, string $target): bool
    {
        $sources = [];
        foreach (self::COLUMN_MAP as $old => $new) {
            if ($new === $target && in_array($old, $columns, true) === true) {
                $sources[] = $old;
            }
        }

        if (count($sources) < 2) {
            return false;
        }

        $this->logger->warning(
            'RenameDutchDeadlineColumns: refusing an ambiguous rename; two source columns target one destination.',
            ['table' => $table, 'sources' => $sources, 'target' => $target]
        );

        return true;

    }//end hasCollision()

    /**
     * Resolve the shard tables of every register whose slug starts with the prefix.
     *
     * @return array<int, string>
     */
    private function shardTables(): array
    {
        try {
            $ids = $this->db->executeQuery(
                'SELECT id FROM `*PREFIX*openregister_registers` WHERE slug LIKE ?',
                [self::REGISTER_SLUG_PREFIX.'%']
            )->fetchAll(\PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            $this->logger->warning(
                'RenameDutchDeadlineColumns: could not resolve the procest registers; skipping.',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        if ($ids === []) {
            return [];
        }

        // Table discovery goes through information_schema, NOT IDBConnection.
        // OCP\IDBConnection exposes neither getSchema() nor getPrefix() — it has
        // only getQueryBuilder/getDatabasePlatform/getDatabaseProvider and a
        // couple of shard helpers. Calling either is a runtime fatal that
        // `php -l` and phpcs both report as clean; only phpstan catches it.
        //
        // The pattern here follows openregister's own RegisterService: match on
        // the `openregister_table_` MARKER rather than a computed prefix.
        // getQueryBuilder()->getTableName('') yields the literal `*PREFIX*`
        // placeholder, which is resolved only when a query runs through the NC
        // DB layer — a raw information_schema string never is, so building a
        // LIKE from it matches zero tables and reports every register empty.
        try {
            $stmt = $this->db->prepare(
                'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'
            );
            $stmt->bindValue('pattern', '%openregister\_table\_%');
            $stmt->execute();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'RenameDutchDeadlineColumns: could not list tables; skipping.',
                ['exception' => $e->getMessage()]
            );
            return [];
        }

        $markers = [];
        foreach ($ids as $id) {
            $markers[] = 'openregister_table_'.((int) $id).'_';
        }

        $tables = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $name = (string) ($row['table_name'] ?? '');
            if ($this->isShardOf(table: $name, markers: $markers) === true) {
                $tables[] = $name;
            }
        }

        return array_values(array_unique($tables));

    }//end shardTables()

    /**
     * Whether a table name is a shard of one of the given registers.
     *
     * @param string             $table   Table name from information_schema.
     * @param array<int, string> $markers `openregister_table_<registerId>_` prefixes.
     *
     * @return bool
     */
    private function isShardOf(string $table, array $markers): bool
    {
        if ($table === '') {
            return false;
        }

        foreach ($markers as $marker) {
            $offset = strpos($table, $marker);
            if ($offset === false) {
                continue;
            }

            // Everything after the marker must be the numeric schema id, so
            // register 17 cannot match register 170's tables.
            if (ctype_digit(substr($table, ($offset + strlen($marker)))) === true) {
                return true;
            }
        }

        return false;

    }//end isShardOf()

    /**
     * List the column names of a table.
     *
     * @param string $table Table name.
     *
     * @return array<int, string>
     */
    private function columnsOf(string $table): array
    {
        // Queried from information_schema for the same reason as shardTables():
        // IDBConnection has no getSchema().
        try {
            $stmt = $this->db->prepare(
                'SELECT column_name FROM information_schema.columns WHERE table_name = :table'
            );
            $stmt->bindValue('table', $table);
            $stmt->execute();
        } catch (\Throwable $e) {
            $this->logger->warning(
                'RenameDutchDeadlineColumns: could not read columns; skipping table.',
                ['table' => $table, 'exception' => $e->getMessage()]
            );
            return [];
        }

        $columns = [];
        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $name = (string) ($row['column_name'] ?? '');
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
    private function exec(string $sql): bool
    {
        try {
            $this->db->executeStatement($sql);
            return true;
        } catch (Exception $e) {
            $this->logger->warning(
                'RenameDutchDeadlineColumns: statement failed; leaving the column as it was.',
                ['sql' => $sql, 'exception' => $e->getMessage()]
            );
            return false;
        }

    }//end exec()

    /**
     * Quote an identifier for the active platform.
     *
     * @param string $identifier Table or column name.
     *
     * @return string
     */
    private function quote(string $identifier): string
    {
        return $this->db->getDatabasePlatform()->quoteSingleIdentifier($identifier);

    }//end quote()
}//end class
