<?php

/**
 * Unit tests for ReadsRegisterShards: the register ids and table listing the
 * shard repairs walk, the shard predicate over the register markers, the
 * column listing, the swallowed statement failures and identifier quoting.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair\Support;

use OCA\Dossiq\Repair\Support\ReadsRegisterShards;
use OCP\DB\Exception;
use OCP\DB\IPreparedStatement;
use OCP\DB\IResult;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * @covers \OCA\Dossiq\Repair\Support\ReadsRegisterShards
 * @covers \OCA\Dossiq\Repair\Support\ListsOpenRegisterTables
 */
class ReadsRegisterShardsTest extends TestCase {

	/** @var IDBConnection&MockObject */
	private IDBConnection $db;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	protected function setUp(): void {
		parent::setUp();
		$this->db     = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * A repair host that exposes the trait's private helpers.
	 */
	private function host(): object {
		return new class($this->db, $this->logger) {
			use ReadsRegisterShards;

			private const LOG_NAME = 'TestRepair';

			private const REGISTER_SLUG_PREFIX = 'dossiq';

			public function __construct(private IDBConnection $db, private LoggerInterface $logger) {
			}

			public function call(string $method, mixed ...$args): mixed {
				return $this->$method(...$args);
			}
		};
	}//end host()

	/**
	 * A prepared statement whose fetch() yields the rows, then false.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 */
	private function statement(array $rows, array &$bound = []): IPreparedStatement {
		$stmt = $this->createMock(IPreparedStatement::class);
		$stmt->method('bindValue')->willReturnCallback(
			static function ($param, $value) use (&$bound): bool {
				$bound[$param] = $value;
				return true;
			}
		);
		$stmt->method('execute')->willReturn($this->createMock(IResult::class));
		$stmt->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
		return $stmt;
	}//end statement()

	private function registerIds(array $ids): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetchAll')->with(\PDO::FETCH_COLUMN)->willReturn($ids);
		$this->db->expects($this->once())->method('executeQuery')
			->with('SELECT id FROM `*PREFIX*openregister_registers` WHERE slug LIKE ?', ['dossiq%'])
			->willReturn($result);
	}//end registerIds()

	public function testRegisterIdsAreReadBySlugPrefix(): void {
		$this->registerIds([3, '7']);
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame([3, '7'], $this->host()->call('registerIdsOrNone', 'x'));
	}//end testRegisterIdsAreReadBySlugPrefix()

	public function testUnreadableRegistersGiveNoneAndWarnWithTheStepName(): void {
		$this->db->method('executeQuery')->willThrowException(new Exception('db down'));
		$this->logger->expects($this->once())->method('warning')
			->with('TestRepair: could not read.', ['exception' => 'db down']);

		$this->assertSame([], $this->host()->call('registerIdsOrNone', 'could not read.'));
	}//end testUnreadableRegistersGiveNoneAndWarnWithTheStepName()

	public function testTableNamesSkipEmptyAndMissingNames(): void {
		$bound = [];
		$stmt  = $this->statement([['table_name' => 'oc_openregister_table_1_a'], ['table_name' => ''], ['other' => 'x'], ['table_name' => 'oc_openregister_table_2_b']], $bound);
		$this->db->expects($this->once())->method('prepare')
			->with('SELECT table_name FROM information_schema.tables WHERE table_name LIKE :pattern')
			->willReturn($stmt);

		$this->assertSame(['oc_openregister_table_1_a', 'oc_openregister_table_2_b'], $this->host()->call('openRegisterTableNames'));
		$this->assertSame(['pattern' => '%openregister\_table\_%'], $bound);
	}//end testTableNamesSkipEmptyAndMissingNames()

	public function testAnUnlistableSchemaGivesNoTablesAndWarns(): void {
		$this->db->method('prepare')->willThrowException(new \RuntimeException('no schema'));
		$this->logger->expects($this->once())->method('warning')
			->with('TestRepair: could not list tables; skipping.', ['exception' => 'no schema']);

		$this->assertSame([], $this->host()->call('openRegisterTableNames'));
	}//end testAnUnlistableSchemaGivesNoTablesAndWarns()

	public function testShardMarkersCastEachIdToAnInteger(): void {
		$this->assertSame(
			['openregister_table_4_', 'openregister_table_12_', 'openregister_table_0_'],
			$this->host()->call('shardMarkers', ['4', 12, 'abc'])
		);
	}//end testShardMarkersCastEachIdToAnInteger()

	public function testShardTablesKeepOnlyWhatThePredicateAcceptsGivenTheMarkers(): void {
		$this->registerIds([5]);
		$this->db->method('prepare')->willReturn(
			$this->statement([['table_name' => 'oc_openregister_table_5_case'], ['table_name' => 'oc_openregister_table_6_case']])
		);
		$seen = [];

		$tables = $this->host()->call(
			'shardTablesWhere',
			static function (string $name, array $markers) use (&$seen): bool {
				$seen[] = [$name, $markers];
				return str_contains($name, $markers[0]);
			}
		);

		$this->assertSame(['oc_openregister_table_5_case'], $tables);
		$this->assertSame(
			[
				['oc_openregister_table_5_case', ['openregister_table_5_']],
				['oc_openregister_table_6_case', ['openregister_table_5_']],
			],
			$seen
		);
	}//end testShardTablesKeepOnlyWhatThePredicateAcceptsGivenTheMarkers()

	public function testWithoutRegistersNoTableIsListed(): void {
		$this->registerIds([]);
		$this->db->expects($this->never())->method('prepare');

		$this->assertSame([], $this->host()->call('shardTablesWhere', static fn (): bool => true));
	}//end testWithoutRegistersNoTableIsListed()

	public function testColumnsOfBindsTheTableAndSkipsEmptyNames(): void {
		$bound = [];
		$this->db->expects($this->once())->method('prepare')
			->with('SELECT column_name FROM information_schema.columns WHERE table_name = :table')
			->willReturn($this->statement([['column_name' => 'id'], ['column_name' => ''], ['column_name' => 'zaaknummer']], $bound));

		$this->assertSame(['id', 'zaaknummer'], $this->host()->call('columnsOf', 'oc_t'));
		$this->assertSame(['table' => 'oc_t'], $bound);
	}//end testColumnsOfBindsTheTableAndSkipsEmptyNames()

	public function testUnreadableColumnsGiveNoneAndNameTheTable(): void {
		$this->db->method('prepare')->willThrowException(new \RuntimeException('gone'));
		$this->logger->expects($this->once())->method('warning')
			->with('TestRepair: could not read columns; skipping table.', ['table' => 'oc_t', 'exception' => 'gone']);

		$this->assertSame([], $this->host()->call('columnsOf', 'oc_t'));
	}//end testUnreadableColumnsGiveNoneAndNameTheTable()

	public function testExecReportsSuccess(): void {
		$this->db->expects($this->once())->method('executeStatement')->with('ALTER TABLE x')->willReturn(0);
		$this->logger->expects($this->never())->method('warning');

		$this->assertTrue($this->host()->call('exec', 'ALTER TABLE x'));
	}//end testExecReportsSuccess()

	public function testExecSwallowsAFailedStatementAndLogsIt(): void {
		$this->db->method('executeStatement')->willThrowException(new Exception('locked'));
		$this->logger->expects($this->once())->method('warning')
			->with('TestRepair: statement failed; leaving the column as it was.', ['sql' => 'ALTER TABLE x', 'exception' => 'locked']);

		$this->assertFalse($this->host()->call('exec', 'ALTER TABLE x'));
	}//end testExecSwallowsAFailedStatementAndLogsIt()

	public function testQuoteUsesTheActivePlatform(): void {
		$platform = new class {
			public function quoteSingleIdentifier(string $identifier): string {
				return '"' . $identifier . '"';
			}
		};
		$this->db->method('getDatabasePlatform')->willReturn($platform);

		$this->assertSame('"case_number"', $this->host()->call('quote', 'case_number'));
	}//end testQuoteUsesTheActivePlatform()
}//end class
