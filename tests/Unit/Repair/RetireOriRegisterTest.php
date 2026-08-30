<?php

/**
 * Tests for the fail-safe ORI register retirement.
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Repair
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://www.conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\RetireOriRegister;
use OCP\DB\IResult;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use ReflectionClass;

/**
 * All four branches, and hardest on the one that must say NO.
 *
 * phpcs:disable CustomSniffs.Functions.NamedParameters
 *
 * @covers \OCA\Dossiq\Repair\RetireOriRegister
 *
 * @spec openspec/changes/ori-removal/specs/ori-removal/spec.md
 */
final class RetireOriRegisterTest extends TestCase {

	/**
	 * Build a result whose fetch()/fetchAll() return the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows Rows to return.
	 *
	 * @return IResult&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function rowsResult(array $rows) {
		$r = $this->createMock(IResult::class);
		$r->method('fetch')->willReturn($rows[0] ?? false);
		$r->method('fetchAll')->willReturn($rows);

		return $r;
	}//end rowsResult()

	/**
	 * No `ori` register on this install is a no-op.
	 *
	 * @return void
	 */
	public function testAnAbsentRegisterIsANoOp(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturn($this->rowsResult([]));
		$db->expects(self::never())->method('executeStatement');

		$step = new RetireOriRegister($db, $this->createMock(LoggerInterface::class));

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('nothing to do'));

		$step->run($output);

	}//end testAnAbsentRegisterIsANoOp()

	/**
	 * A register that still holds objects is KEPT, and says how many.
	 *
	 * This is the branch the whole step exists for. A guard that cannot say NO
	 * is not a guard: seed one unmigrated object and the register must survive.
	 *
	 * @return void
	 */
	public function testARegisterWithObjectsIsKept(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->rowsResult([['id' => 2481]]),
			$this->rowsResult([['table_name' => 'oc_openregister_table_2481_4926']]),
			$this->rowsResult([['c' => 1]])
		);
		$db->expects(self::never())->method('executeStatement');

		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')->with(self::stringContains('KEEPING it'));

		$step = new RetireOriRegister($db, $logger);

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('warning')->with(self::stringContains('still holds 1 object'));

		$step->run($output);

	}//end testARegisterWithObjectsIsKept()

	/**
	 * An empty register is retired.
	 *
	 * @return void
	 */
	public function testAnEmptyRegisterIsRetired(): void {
		$written = [];
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->rowsResult([['id' => 2481]]),
			$this->rowsResult([['table_name' => 'oc_openregister_table_2481_4926']]),
			$this->rowsResult([['c' => 0]])
		);
		$db->method('executeStatement')->willReturnCallback(
			function (string $sql, array $params) use (&$written): int {
				$written[] = $params;
				return 1;
			}
		);

		$step = new RetireOriRegister($db, $this->createMock(LoggerInterface::class));

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('has been retired'));

		$step->run($output);

		self::assertSame([[2481, 'ori']], $written);

	}//end testAnEmptyRegisterIsRetired()

	/**
	 * A count that cannot be established must KEEP the register.
	 *
	 * An unreadable count must never be able to authorise a deletion — the
	 * failure direction matters more here than anywhere else in the step.
	 *
	 * @return void
	 */
	public function testAnUnreadableCountKeepsTheRegister(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnCallback(
			function (string $sql) {
				if (str_contains($sql, 'openregister_registers') === true) {
					return $this->rowsResult([['id' => 2481]]);
				}

				throw new \OCP\DB\Exception('information_schema unavailable');
			}
		);
		$db->expects(self::never())->method('executeStatement');

		$step = new RetireOriRegister($db, $this->createMock(LoggerInterface::class));

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('keeping it'));

		$step->run($output);

	}//end testAnUnreadableCountKeepsTheRegister()

	/**
	 * A shard table whose name is not provably this register's is skipped.
	 *
	 * The count query cannot be parameterised on an identifier, so the name has
	 * to be shape-checked before it reaches SQL.
	 *
	 * @return void
	 */
	public function testAnUnrecognisedTableNameIsNotCounted(): void {
		$db = $this->createMock(IDBConnection::class);
		$db->method('executeQuery')->willReturnOnConsecutiveCalls(
			$this->rowsResult([['id' => 2481]]),
			$this->rowsResult([['table_name' => 'oc_openregister_table_2481_4926; DROP TABLE x']])
		);
		$db->method('executeStatement')->willReturn(1);

		$step = new RetireOriRegister($db, $this->createMock(LoggerInterface::class));

		$output = $this->createMock(IOutput::class);
		$output->expects(self::once())->method('info')->with(self::stringContains('has been retired'));

		$step->run($output);

	}//end testAnUnrecognisedTableNameIsNotCounted()

	/**
	 * It is a repair step and names itself.
	 *
	 * @return void
	 */
	public function testItIsARepairStep(): void {
		self::assertTrue(
			(new ReflectionClass(RetireOriRegister::class))->implementsInterface(IRepairStep::class)
		);
		self::assertSame('ori', RetireOriRegister::REGISTER_SLUG);
		self::assertNotSame('', (new RetireOriRegister(
			$this->createMock(IDBConnection::class),
			$this->createMock(LoggerInterface::class)
		))->getName());

	}//end testItIsARepairStep()
}//end class
