<?php

/**
 * Unit tests for RetireOriRegister when the database fails or answers oddly.
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

use OCA\Dossiq\Repair\RetireOriRegister;
use OCP\DB\Exception;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every failure keeps the register: a register is only deleted when it is provably empty.
 */
#[CoversClass(RetireOriRegister::class)]
final class RetireOriRegisterFailuresTest extends TestCase {

	/**
	 * The database.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection&MockObject $db;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The output.
	 *
	 * @var IOutput&MockObject
	 */
	private IOutput&MockObject $output;

	/**
	 * Wire the mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->output = $this->createMock(IOutput::class);
	}//end setUp()

	/**
	 * A result answering these rows.
	 *
	 * @param list<array<string,mixed>> $rows The rows.
	 *
	 * @return IResult
	 */
	private function rows(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(($rows[0] ?? false));
		$result->method('fetchAll')->willReturn($rows);
		return $result;
	}//end rows()

	/**
	 * Run the step.
	 *
	 * @return void
	 */
	private function retire(): void {
		(new RetireOriRegister(db: $this->db, logger: $this->logger))->run($this->output);
	}//end retire()

	/**
	 * The step names itself.
	 *
	 * @return void
	 */
	public function testTheStepNamesItself(): void {
		self::assertSame(
			'Retire the ORI register once its objects have moved to decidiq',
			(new RetireOriRegister(db: $this->db, logger: $this->logger))->getName()
		);
	}//end testTheStepNamesItself()

	/**
	 * A failing register lookup is logged and treated as no register.
	 *
	 * @return void
	 */
	public function testAFailingLookupLeavesTheRegisterAlone(): void {
		$this->db->method('executeQuery')->willThrowException(new Exception('no such table'));
		$this->db->expects(self::never())->method('executeStatement');
		$this->logger->expects(self::once())->method('info')->with(
			'RetireOriRegister: could not look up the register; leaving it alone.',
			['exception' => 'no such table']
		);
		$this->output->expects(self::once())->method('info')->with(self::stringContains('nothing to do'));

		$this->retire();
	}//end testAFailingLookupLeavesTheRegisterAlone()

	/**
	 * Failing to list the shard tables keeps the register.
	 *
	 * @return void
	 */
	public function testAFailingTableListKeepsTheRegister(): void {
		$calls = 0;
		$this->db->method('executeQuery')->willReturnCallback(
			function () use (&$calls): IResult {
				$calls++;
				if ($calls === 2) {
					throw new Exception('permission denied');
				}

				return $this->rows([['id' => 2481]]);
			}
		);
		$this->db->expects(self::never())->method('executeStatement');
		$this->logger->expects(self::once())->method('warning')->with(
			'RetireOriRegister: could not list the register\'s shard tables; keeping the register.',
			['exception' => 'permission denied']
		);
		$this->output->expects(self::once())->method('info')->with(
			'RetireOriRegister: could not count the register\'s objects; keeping it.'
		);

		$this->retire();
	}//end testAFailingTableListKeepsTheRegister()

	/**
	 * Failing to count one shard keeps the register.
	 *
	 * @return void
	 */
	public function testAFailingShardCountKeepsTheRegister(): void {
		$calls = 0;
		$this->db->method('executeQuery')->willReturnCallback(
			function () use (&$calls): IResult {
				$calls++;
				if ($calls === 3) {
					throw new Exception('locked');
				}

				if ($calls === 2) {
					return $this->rows([['table_name' => 'oc_openregister_table_2481_4926']]);
				}

				return $this->rows([['id' => 2481]]);
			}
		);
		$this->db->expects(self::never())->method('executeStatement');
		$this->logger->expects(self::once())->method('warning')->with(
			'RetireOriRegister: could not count a shard table; keeping the register.',
			['table' => 'oc_openregister_table_2481_4926', 'exception' => 'locked']
		);

		$this->retire();
	}//end testAFailingShardCountKeepsTheRegister()

	/**
	 * A table name that is not one of this register's shards is never counted.
	 *
	 * @return void
	 */
	public function testAForeignTableNameIsNeverCounted(): void {
		$sql = [];
		$this->db->method('executeQuery')->willReturnCallback(
			function (string $query) use (&$sql): IResult {
				$sql[] = $query;
				if (count($sql) === 1) {
					return $this->rows([['id' => 2481]]);
				}

				return $this->rows([['table_name' => 'oc_openregister_table_2481_1"; DROP TABLE x; --'], ['table_name' => 'oc_openregister_table_9_1']]);
			}
		);
		$this->db->expects(self::once())->method('executeStatement')->willReturn(1);

		$this->retire();

		self::assertCount(2, $sql, 'Only the lookup and the table list ran; no count against a foreign name.');
	}//end testAForeignTableNameIsNeverCounted()

	/**
	 * A failing delete is logged and the register reported unchanged.
	 *
	 * @return void
	 */
	public function testAFailingDeleteReportsTheRegisterUnchanged(): void {
		$this->db->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->rows([['id' => 2481]]),
			$this->rows([])
		);
		$this->db->method('executeStatement')->willThrowException(new Exception('foreign key'));
		$this->logger->expects(self::once())->method('warning')->with(
			'RetireOriRegister: deleting the register failed.',
			['register' => 'ori', 'exception' => 'foreign key']
		);
		$this->output->expects(self::once())->method('info')->with(
			'RetireOriRegister: could not retire the `ori` register; it is unchanged.'
		);

		$this->retire();
	}//end testAFailingDeleteReportsTheRegisterUnchanged()
}//end class
