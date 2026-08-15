<?php

/**
 * Storage seam for the Dutch-to-English value migration.
 *
 * The step needs three things from the database and nothing else. Naming them
 * here lets the step's own logic be exercised against a fake, which matters
 * because `createMock(IDBConnection)` cannot be built in the unit environment:
 * PHPUnit resolves the interface's parameter types while constructing the
 * double, and `Doctrine\DBAL\ParameterType` is absent there. The failure is in
 * BUILDING the double, so no amount of stubbing gets past it.
 *
 * @category Repair
 * @package  OCA\Procest\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Procest\Repair;

/**
 * The three storage operations the value migration performs.
 */
interface ValueMigrationPort
{
    /**
     * Every OpenRegister shard table on this install.
     *
     * @return array<int, string> Table names, empty when none or unreadable.
     *
     * @spec exclude Seam for the Dutch-to-English vocabulary migration.
     */
    public function shardTables(): array;

    /**
     * The columns a table has.
     *
     * @param string $table Table name.
     *
     * @return array<int, string> Column names, empty when unreadable.
     *
     * @spec exclude Seam for the Dutch-to-English vocabulary migration.
     */
    public function columnsOf(string $table): array;

    /**
     * Rewrite one stored value to another in one column.
     *
     * @param string $table  Table name.
     * @param string $column Column name.
     * @param string $old    The stored Dutch value.
     * @param string $new    The English replacement.
     *
     * @return int Rows affected; 0 when the statement failed.
     *
     * @spec exclude Seam for the Dutch-to-English vocabulary migration.
     */
    public function rewrite(string $table, string $column, string $old, string $new): int;
}//end interface
