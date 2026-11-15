<?php

/**
 * Dossiq DbValueMigrationPort test.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\DbValueMigrationPort;
use OCA\Dossiq\Repair\RenameDutchValueDecisions;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The value migration only offers columns that can hold a text value.
 *
 * @covers \OCA\Dossiq\Repair\DbValueMigrationPort
 * @covers \OCA\Dossiq\Repair\Support\ListsOpenRegisterTables
 * @uses \OCA\Dossiq\Repair\RenameDutchValueDecisions
 */
class DbValueMigrationPortTest extends TestCase {

	/**
	 * The column query asks for text columns only, so another app's integer or
	 * jsonb column of the same name (a task's priority, a build's items) is
	 * never compared with a string.
	 *
	 * @return void
	 */
	public function testColumnsOfListsTextColumnsOnly(): void {
		$sql       = '';
		$result    = $this->createMock(originalClassName: IResult::class);
		$statement = $this->createMock(originalClassName: IPreparedStatement::class);
		$statement->method('execute')->willReturn(value: $result);
		$statement->method('fetchAll')->willReturn(value: [['column_name' => 'status']]);
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('prepare')->willReturnCallback(
			callback: static function (string $query) use (&$sql, $statement): IPreparedStatement {
				$sql = $query;
				return $statement;
			}
		);

		$port    = new DbValueMigrationPort(
			db: $db,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			decisions: new RenameDutchValueDecisions()
		);
		$columns = $port->columnsOf(table: 'oc_openregister_table_8_9');

		$this->assertSame(expected: ['status'], actual: $columns);
		$this->assertStringContainsString(needle: 'data_type IN', haystack: $sql);
		foreach (DbValueMigrationPort::TEXT_TYPES as $type) {
			$this->assertStringContainsString(needle: "'".$type."'", haystack: $sql);
		}

		$this->assertNotContains(needle: 'integer', haystack: DbValueMigrationPort::TEXT_TYPES);
		$this->assertNotContains(needle: 'jsonb', haystack: DbValueMigrationPort::TEXT_TYPES);
	}//end testColumnsOfListsTextColumnsOnly()

	/**
	 * The shard tables are every OpenRegister table information_schema lists; a listing that fails answers none and says so.
	 *
	 * @return void
	 */
	public function testShardTablesListsOpenRegisterTablesOrNone(): void {
		$calls = [];
		$statement = $this->createMock(originalClassName: IPreparedStatement::class);
		$statement->method('bindValue')->willReturnCallback(
			callback: static function (mixed $param, mixed $value) use (&$calls): bool {
				$calls[] = ['bindValue', $param, $value];
				return true;
			}
		);
		$statement->method('execute')->willReturnCallback(
			callback: function () use (&$calls): IResult {
				$calls[] = ['execute'];
				return $this->createMock(originalClassName: IResult::class);
			}
		);
		$statement->method('fetchAll')->willReturn(value: [['table_name' => 'oc_openregister_table_8_9'], ['other' => 'x']]);
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('prepare')->willReturnCallback(
			callback: static function (string $query) use (&$calls, $statement): IPreparedStatement {
				$calls[] = ['prepare', $query];
				return $statement;
			}
		);

		$port = new DbValueMigrationPort(
			db: $db,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			decisions: new RenameDutchValueDecisions()
		);

		$this->assertSame(expected: ['oc_openregister_table_8_9', ''], actual: $port->shardTables());
		$this->assertSame(
			expected: [
				['prepare', 'SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern'],
				['bindValue', 'pattern', '%openregister\\_table\\_%'],
				['execute'],
			],
			actual: $calls
		);

		$warnings = [];
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			callback: static function (string $message) use (&$warnings): void {
				$warnings[] = $message;
			}
		);
		$failing = $this->createMock(originalClassName: IDBConnection::class);
		$failing->method('prepare')->willThrowException(exception: new \RuntimeException('down'));

		$port = new DbValueMigrationPort(db: $failing, logger: $logger, decisions: new RenameDutchValueDecisions());

		$this->assertSame(expected: [], actual: $port->shardTables());
		$this->assertSame(expected: ['DbValueMigrationPort: could not list tables.'], actual: $warnings);
	}//end testShardTablesListsOpenRegisterTablesOrNone()

	/**
	 * An unreadable column listing is logged with its table and yields no columns.
	 *
	 * @return void
	 */
	public function testColumnsOfLogsAndReturnsNoneWhenTheListingFails(): void {
		$logged = [];
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			callback: static function (string $message, array $context) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('prepare')->willThrowException(exception: new \RuntimeException('gone'));

		$port = new DbValueMigrationPort(db: $db, logger: $logger, decisions: new RenameDutchValueDecisions());

		$this->assertSame(expected: [], actual: $port->columnsOf(table: 'oc_openregister_table_1_2'));
		$this->assertSame(
			expected: [['DbValueMigrationPort: could not read columns.', ['table' => 'oc_openregister_table_1_2', 'exception' => 'gone']]],
			actual: $logged
		);
	}//end testColumnsOfLogsAndReturnsNoneWhenTheListingFails()

	/**
	 * A database platform that wraps identifiers in double quotes.
	 *
	 * @return object The platform.
	 */
	private function quotingPlatform(): object {
		return new class {
			/**
			 * Quote one identifier.
			 *
			 * @param string $identifier The identifier.
			 *
			 * @return string The quoted identifier.
			 */
			public function quoteSingleIdentifier(string $identifier): string {
				return '"' . $identifier . '"';
			}//end quoteSingleIdentifier()
		};
	}//end quotingPlatform()

	/**
	 * A rewrite quotes table and column, binds new then old, and returns the rows changed.
	 *
	 * @return void
	 */
	public function testRewriteUpdatesTheExactValueAndReturnsTheRowCount(): void {
		$calls = [];
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(value: $this->quotingPlatform());
		$db->method('executeStatement')->willReturnCallback(
			callback: static function (string $sql, array $params) use (&$calls): int {
				$calls[] = [$sql, $params];
				return 4;
			}
		);

		$port = new DbValueMigrationPort(
			db: $db,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			decisions: new RenameDutchValueDecisions()
		);

		$this->assertSame(expected: 4, actual: $port->rewrite(table: 'oc_t', column: 'status', old: 'open', new: 'opened'));
		$this->assertSame(
			expected: [['UPDATE "oc_t" SET "status" = ? WHERE "status" = ?', ['opened', 'open']]],
			actual: $calls
		);
	}//end testRewriteUpdatesTheExactValueAndReturnsTheRowCount()

	/**
	 * A failing rewrite leaves the value, logs the statement and reports no rows.
	 *
	 * @return void
	 */
	public function testAFailingRewriteIsLoggedAndCountsNothing(): void {
		$logged = [];
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			callback: static function (string $message, array $context) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getDatabasePlatform')->willReturn(value: $this->quotingPlatform());
		$db->method('executeStatement')->willThrowException(exception: new \RuntimeException('locked'));

		$port = new DbValueMigrationPort(db: $db, logger: $logger, decisions: new RenameDutchValueDecisions());

		$this->assertSame(expected: 0, actual: $port->rewrite(table: 'oc_t', column: 'kind', old: 'a', new: 'b'));
		$this->assertSame(
			expected: [[
				'DbValueMigrationPort: statement failed; leaving the value as it was.',
				['sql' => 'UPDATE "oc_t" SET "kind" = ? WHERE "kind" = ?', 'exception' => 'locked'],
			]],
			actual: $logged
		);
	}//end testAFailingRewriteIsLoggedAndCountsNothing()
}//end class
