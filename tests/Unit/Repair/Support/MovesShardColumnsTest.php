<?php

/**
 * Unit tests for MovesShardColumns: each mapped column a shard table still
 * carries is renamed when its destination is free, copied into the
 * destination when both exist, left alone when the repair refuses the move,
 * and counted by outcome.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Support;

use OCA\Dossiq\Repair\Support\ColumnMove;
use OCA\Dossiq\Repair\Support\ColumnMoveTally;
use OCA\Dossiq\Repair\Support\MovesShardColumns;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\Support\MovesShardColumns
 *
 * @uses \OCA\Dossiq\Repair\Support\ColumnMove
 * @uses \OCA\Dossiq\Repair\Support\ColumnMoveTally
 */
class MovesShardColumnsTest extends TestCase {

	/**
	 * A repair host with fixed table columns that records every statement.
	 *
	 * @param array<string, list<string>> $columns Columns per table.
	 * @param list<string>                $refuse  Old column names the repair refuses to move.
	 * @param bool                        $execOk  What every statement reports.
	 */
	private function host(array $columns, array $refuse = [], bool $execOk = true): object {
		return new class($columns, $refuse, $execOk) {
			use MovesShardColumns;

			private const COLUMN_MAP = [
				'zaaknummer' => 'case_number',
				'omschrijving' => 'description',
			];

			/** @var list<string> */
			public array $statements = [];

			/** @var list<array{string, string, string}> */
			public array $asked = [];

			public function __construct(private array $columns, private array $refuse, private bool $execOk) {
			}

			public function moveAll(array $tables): ColumnMoveTally {
				return $this->moveColumnsOfAll(tables: $tables);
			}

			protected function refusesMove(string $table, ColumnMove $move): bool {
				$this->asked[] = [$table, $move->old, $move->new];
				return in_array($move->old, $this->refuse, true);
			}

			private function columnsOf(string $table): array {
				return $this->columns[$table] ?? [];
			}

			private function quote(string $identifier): string {
				return '`' . $identifier . '`';
			}

			private function exec(string $sql): bool {
				$this->statements[] = $sql;
				return $this->execOk;
			}
		};
	}//end host()

	public function testAColumnWithAFreeDestinationIsRenamed(): void {
		$host  = $this->host(['t1' => ['id', 'zaaknummer']]);
		$tally = $host->moveAll(['t1']);

		$this->assertSame(['ALTER TABLE `t1` RENAME COLUMN `zaaknummer` TO `case_number`'], $host->statements);
		$this->assertSame([1, 0, 0], [$tally->renamed, $tally->copied, $tally->refused]);
	}//end testAColumnWithAFreeDestinationIsRenamed()

	public function testAColumnWhoseDestinationExistsIsCopiedIntoTheEmptyCells(): void {
		$host  = $this->host(['t1' => ['id', 'omschrijving', 'description']]);
		$tally = $host->moveAll(['t1']);

		$this->assertSame(
			['UPDATE `t1` SET `description` = `omschrijving` WHERE `description` IS NULL AND `omschrijving` IS NOT NULL'],
			$host->statements
		);
		$this->assertSame([0, 1, 0], [$tally->renamed, $tally->copied, $tally->refused]);
	}//end testAColumnWhoseDestinationExistsIsCopiedIntoTheEmptyCells()

	public function testARefusedMoveRunsNoStatementAndIsCountedAsRefused(): void {
		$host  = $this->host(['t1' => ['zaaknummer', 'omschrijving']], ['zaaknummer']);
		$tally = $host->moveAll(['t1']);

		$this->assertSame(['ALTER TABLE `t1` RENAME COLUMN `omschrijving` TO `description`'], $host->statements);
		$this->assertSame([1, 0, 1], [$tally->renamed, $tally->copied, $tally->refused]);
		$this->assertSame(
			[['t1', 'zaaknummer', 'case_number'], ['t1', 'omschrijving', 'description']],
			$host->asked
		);
	}//end testARefusedMoveRunsNoStatementAndIsCountedAsRefused()

	public function testAnAlreadyMigratedTableIsNotTouched(): void {
		$host  = $this->host(['t1' => ['id', 'case_number', 'description']]);
		$tally = $host->moveAll(['t1']);

		$this->assertSame([], $host->statements);
		$this->assertSame([], $host->asked);
		$this->assertSame([0, 0, 0], [$tally->renamed, $tally->copied, $tally->refused]);
	}//end testAnAlreadyMigratedTableIsNotTouched()

	public function testAFailedStatementIsNotCounted(): void {
		$host  = $this->host(['t1' => ['zaaknummer'], 't2' => ['omschrijving', 'description']], [], false);
		$tally = $host->moveAll(['t1', 't2']);

		$this->assertCount(2, $host->statements);
		$this->assertSame([0, 0, 0], [$tally->renamed, $tally->copied, $tally->refused]);
	}//end testAFailedStatementIsNotCounted()

	public function testOneTallyAddsUpEveryTable(): void {
		$host  = $this->host(
			[
				't1' => ['zaaknummer'],
				't2' => ['zaaknummer', 'case_number', 'omschrijving'],
				't3' => [],
			]
		);
		$tally = $host->moveAll(['t1', 't2', 't3']);

		$this->assertSame([2, 1, 0], [$tally->renamed, $tally->copied, $tally->refused]);
		$this->assertCount(3, $host->statements);
	}//end testOneTallyAddsUpEveryTable()
}//end class
