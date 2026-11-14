<?php

/**
 * The once-per-deadline ledger of term notices: claim, find, settle, release.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/termijn-notices-send/specs/burger-notifications/spec.md#requirement-a-term-notice-is-sent-once-per-deadline-req-term-071
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Service\Termijn\TermNoticeLedger;
use OCP\DB\Exception as DbException;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Termijn\TermNoticeLedger
 */
class TermNoticeLedgerTest extends TestCase {

	/**
	 * The query builder the connection hands out.
	 *
	 * @var IQueryBuilder&MockObject
	 */
	private IQueryBuilder $query;

	/**
	 * Named parameters created, in order, as [value, type].
	 *
	 * @var array<int, array{0: mixed, 1: mixed}>
	 */
	private array $params = [];

	/**
	 * A chainable query builder whose named parameters echo as ':pN'.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->params = [];
		$this->query = $this->createMock(IQueryBuilder::class);
		$this->query->method('createNamedParameter')->willReturnCallback(
			function (mixed $value, mixed $type = IQueryBuilder::PARAM_STR): string {
				$this->params[] = [$value, $type];
				return ':p' . (count($this->params) - 1);
			}
		);

		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $column, string $param): string => $column . ' = ' . $param);
		$this->query->method('expr')->willReturn($expr);

		foreach (['insert', 'values', 'select', 'from', 'where', 'update', 'set', 'delete'] as $method) {
			$this->query->method($method)->willReturnSelf();
		}
	}//end setUp()

	/**
	 * The ledger over a connection handing out the query builder.
	 *
	 * @return TermNoticeLedger
	 */
	private function ledger(): TermNoticeLedger {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($this->query);

		return new TermNoticeLedger(db: $db);
	}//end ledger()

	public function testClaimInsertsAPendingRowWithTruncatedReferences(): void {
		$this->query->expects($this->once())->method('insert')->with(TermNoticeLedger::TABLE);
		$this->query->expects($this->once())->method('values')->with([
			'notice_key' => ':p0',
			'template' => ':p1',
			'instance_ref' => ':p2',
			'outcome' => ':p3',
			'updated_at' => ':p4',
		]);
		$this->query->expects($this->once())->method('executeStatement')->willReturn(1);

		self::assertTrue($this->ledger()->claim(key: 'k-1', template: str_repeat('t', 80), instance: 'ti-1', now: 1700000000));
		self::assertSame(
			[
				['k-1', IQueryBuilder::PARAM_STR],
				[str_repeat('t', 64), IQueryBuilder::PARAM_STR],
				['ti-1', IQueryBuilder::PARAM_STR],
				[TermNoticeLedger::PENDING, IQueryBuilder::PARAM_STR],
				[1700000000, IQueryBuilder::PARAM_INT],
			],
			$this->params
		);
	}//end testClaimInsertsAPendingRowWithTruncatedReferences()

	public function testASecondClaimOnTheSameKeyLosesQuietly(): void {
		$duplicate = $this->createMock(DbException::class);
		$duplicate->method('getReason')->willReturn(DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION);
		$this->query->method('executeStatement')->willThrowException($duplicate);

		self::assertFalse($this->ledger()->claim(key: 'k-1', template: 'reminder', instance: 'ti-1', now: 1));
	}//end testASecondClaimOnTheSameKeyLosesQuietly()

	public function testAnyOtherDatabaseFailureIsRethrown(): void {
		$failure = $this->createMock(DbException::class);
		$failure->method('getReason')->willReturn(DbException::REASON_CONNECTION_LOST);
		$this->query->method('executeStatement')->willThrowException($failure);

		$this->expectException(DbException::class);
		$this->ledger()->claim(key: 'k-1', template: 'reminder', instance: 'ti-1', now: 1);
	}//end testAnyOtherDatabaseFailureIsRethrown()

	public function testFindAnswersTheOutcomeAndMomentOfTheKey(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(['outcome' => 'sent', 'updated_at' => '1700000123']);
		$result->expects($this->once())->method('closeCursor');

		$this->query->expects($this->once())->method('select')->with('outcome', 'updated_at');
		$this->query->expects($this->once())->method('from')->with(TermNoticeLedger::TABLE);
		$this->query->expects($this->once())->method('where')->with('notice_key = :p0');
		$this->query->method('executeQuery')->willReturn($result);

		self::assertSame(['outcome' => 'sent', 'at' => 1700000123], $this->ledger()->find(key: 'k-7'));
		self::assertSame([['k-7', IQueryBuilder::PARAM_STR]], $this->params);
	}//end testFindAnswersTheOutcomeAndMomentOfTheKey()

	public function testFindAnswersNullForAnUnknownKey(): void {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturn(false);
		$result->expects($this->once())->method('closeCursor');
		$this->query->method('executeQuery')->willReturn($result);

		self::assertNull($this->ledger()->find(key: 'k-none'));
	}//end testFindAnswersNullForAnUnknownKey()

	public function testSettleRecordsTheTruncatedOutcomeAndMoment(): void {
		$sets = [];
		$this->query = $this->createMock(IQueryBuilder::class);
		$this->setUpWithSetRecorder(sets: $sets);
		$this->query->expects($this->once())->method('update')->with(TermNoticeLedger::TABLE);
		$this->query->expects($this->once())->method('where')->with('notice_key = :p2');
		$this->query->expects($this->once())->method('executeStatement');

		$this->ledger()->settle(key: 'k-3', outcome: str_repeat('x', 70), now: 42);

		self::assertSame([['outcome', ':p0'], ['updated_at', ':p1']], $sets);
		self::assertSame([[str_repeat('x', 64), IQueryBuilder::PARAM_STR], [42, IQueryBuilder::PARAM_INT], ['k-3', IQueryBuilder::PARAM_STR]], $this->params);
	}//end testSettleRecordsTheTruncatedOutcomeAndMoment()

	public function testReleaseDeletesTheKeysRow(): void {
		$this->query->expects($this->once())->method('delete')->with(TermNoticeLedger::TABLE);
		$this->query->expects($this->once())->method('where')->with('notice_key = :p0');
		$this->query->expects($this->once())->method('executeStatement');

		$this->ledger()->release(key: 'k-9');

		self::assertSame([['k-9', IQueryBuilder::PARAM_STR]], $this->params);
	}//end testReleaseDeletesTheKeysRow()

	/**
	 * Rebuild the query builder so `set` calls are recorded.
	 *
	 * @param array<int, array{0: string, 1: string}> $sets Receives each set call.
	 *
	 * @return void
	 */
	private function setUpWithSetRecorder(array &$sets): void {
		$this->params = [];
		$this->query->method('createNamedParameter')->willReturnCallback(
			function (mixed $value, mixed $type = IQueryBuilder::PARAM_STR): string {
				$this->params[] = [$value, $type];
				return ':p' . (count($this->params) - 1);
			}
		);
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(static fn (string $column, string $param): string => $column . ' = ' . $param);
		$this->query->method('expr')->willReturn($expr);
		$this->query->method('set')->willReturnCallback(
			function (string $column, string $value) use (&$sets): IQueryBuilder {
				$sets[] = [$column, $value];
				return $this->query;
			}
		);
		foreach (['update', 'where'] as $method) {
			$this->query->method($method)->willReturnSelf();
		}
	}//end setUpWithSetRecorder()
}//end class
