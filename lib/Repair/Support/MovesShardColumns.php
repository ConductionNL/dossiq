<?php

/**
 * Moves Dutch columns of the shard tables to their English names, for the column repairs.
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

/**
 * Moves every mapped Dutch column of a shard table to its English name.
 *
 * The using repair carries a `COLUMN_MAP` (Dutch name => English name), uses
 * ReadsRegisterShards for its reads and writes, and decides which moves it
 * refuses.
 *
 * @spec openspec/specs/case-management/spec.md
 */
trait MovesShardColumns {

	/**
	 * Whether the repair refuses a move, reporting why when it does.
	 *
	 * @param string     $table The shard table.
	 * @param ColumnMove $move  The move.
	 *
	 * @return bool True when the move is left alone.
	 */
	abstract protected function refusesMove(string $table, ColumnMove $move): bool;

	/**
	 * Move the Dutch columns of every shard table, counting what was done.
	 *
	 * @param array<int, string> $tables The shard tables.
	 *
	 * @return ColumnMoveTally The counts.
	 */
	private function moveColumnsOfAll(array $tables): ColumnMoveTally {
		$tally = new ColumnMoveTally();
		foreach ($tables as $table) {
			$this->moveColumnsOf(table: $table, tally: $tally);
		}

		return $tally;
	}//end moveColumnsOfAll()

	/**
	 * Move every Dutch column one shard table carries to its English name.
	 *
	 * @param string          $table The shard table.
	 * @param ColumnMoveTally $tally The counts of this run.
	 *
	 * @return void
	 */
	private function moveColumnsOf(string $table, ColumnMoveTally $tally): void {
		$columns = $this->columnsOf(table: $table);
		foreach (self::COLUMN_MAP as $old => $new) {
			if (in_array($old, $columns, true) === false) {
				// Already migrated, or this schema never had the property.
				continue;
			}

			$move = new ColumnMove(old: $old, new: $new, columns: $columns);
			if ($this->refusesMove(table: $table, move: $move) === true) {
				$tally->refused++;
				continue;
			}

			$tally->count(outcome: $this->moveColumn(table: $table, move: $move));
		}
	}//end moveColumnsOf()

	/**
	 * Move one Dutch column to its English name.
	 *
	 * Renamed when the English column is not there yet. When the mapper
	 * already added an empty English column it is back-filled and the Dutch
	 * one is left, so the move stays reversible.
	 *
	 * @param string     $table The shard table.
	 * @param ColumnMove $move  The column and where it goes.
	 *
	 * @return string What the move did (ColumnMoveTally::RENAMED or COPIED), or '' when the statement failed.
	 */
	private function moveColumn(string $table, ColumnMove $move): string {
		$qTable = $this->quote(identifier: $table);
		$qOld = $this->quote(identifier: $move->old);
		$qNew = $this->quote(identifier: $move->new);
		if ($move->targetExists() === false) {
			$renamed = $this->exec(sql: 'ALTER TABLE ' . $qTable . ' RENAME COLUMN ' . $qOld . ' TO ' . $qNew);
			return $this->outcome(succeeded: $renamed, outcome: ColumnMoveTally::RENAMED);
		}

		$copied = $this->exec(
			sql: 'UPDATE ' . $qTable . ' SET ' . $qNew . ' = ' . $qOld
			. ' WHERE ' . $qNew . ' IS NULL AND ' . $qOld . ' IS NOT NULL'
		);
		return $this->outcome(succeeded: $copied, outcome: ColumnMoveTally::COPIED);
	}//end moveColumn()

	/**
	 * The outcome of a statement: what it did, or '' when it failed.
	 *
	 * @param bool   $succeeded Whether the statement ran.
	 * @param string $outcome   What it did when it ran.
	 *
	 * @return string The outcome.
	 */
	private function outcome(bool $succeeded, string $outcome): string {
		if ($succeeded === true) {
			return $outcome;
		}

		return '';
	}//end outcome()

}//end trait
