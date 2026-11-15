<?php

/**
 * Which Mail accounts a user has, and who owns an account.
 *
 * Golden over MailMessageSource's account reads before they share their
 * query and row reading: accountsOf() and allAccounts() list id, name and
 * email ordered by name (one user's, or everyone's); ownsAccount() and
 * ownerOf() read the owner of one account. A blank input asks nothing; a
 * failed read answers the empty answer and logs a warning.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\MailMessageSource;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The account reads, their queries and their answers.
 *
 * @covers \OCA\Dossiq\Service\Email\MailMessageSource
 *
 * @spec openspec/specs/case-management/spec.md
 */
class MailAccountReadsGoldenTest extends TestCase {
	/**
	 * The query calls made, in order.
	 *
	 * @var array<int, string>
	 */
	private array $calls = [];

	/**
	 * A user's accounts are listed with their fields cast, ordered by name.
	 *
	 * @return void
	 */
	public function testAUsersAccountsAreListed(): void {
		$source = $this->source(rows: [['id' => '3', 'name' => 'Werk', 'email' => 'a@b.nl'], ['id' => null]]);

		self::assertSame(
			expected: [['id' => 3, 'name' => 'Werk', 'email' => 'a@b.nl'], ['id' => 0, 'name' => '', 'email' => '']],
			actual: $source->accountsOf(userId: 'sanne')
		);
		self::assertSame(
			expected: ['select:id,name,email', 'from:mail_accounts', 'param:sanne', 'eq:user_id', 'where', 'orderBy:name ASC', 'execute', 'fetch', 'fetch', 'fetch', 'close'],
			actual: $this->calls
		);
	}//end testAUsersAccountsAreListed()

	/**
	 * Every account is listed the same way, without a user filter.
	 *
	 * @return void
	 */
	public function testEveryAccountIsListed(): void {
		$source = $this->source(rows: [['id' => 7, 'name' => 'Team', 'email' => 't@b.nl']]);

		self::assertSame(expected: [['id' => 7, 'name' => 'Team', 'email' => 't@b.nl']], actual: $source->allAccounts());
		self::assertSame(expected: ['select:id,name,email', 'from:mail_accounts', 'orderBy:name ASC', 'execute', 'fetch', 'fetch', 'close'], actual: $this->calls);
	}//end testEveryAccountIsListed()

	/**
	 * The owner of an account is read by its id, one row at most.
	 *
	 * @return void
	 */
	public function testTheOwnerIsReadByTheAccountId(): void {
		self::assertSame(expected: 'sanne', actual: $this->source(rows: [['user_id' => 'sanne']])->ownerOf(accountId: 4));
		self::assertSame(
			expected: ['select:user_id', 'from:mail_accounts', 'param:4:int', 'eq:id', 'where', 'max:1', 'execute', 'fetch', 'close'],
			actual: $this->calls
		);
		self::assertSame(expected: '', actual: $this->source(rows: [])->ownerOf(accountId: 4));
	}//end testTheOwnerIsReadByTheAccountId()

	/**
	 * Ownership is the stored owner being the given user, and nothing else.
	 *
	 * @return void
	 */
	public function testOwnershipIsTheStoredOwner(): void {
		self::assertTrue($this->source(rows: [['user_id' => 'sanne']])->ownsAccount(accountId: 4, userId: 'sanne'));
		self::assertSame(
			expected: ['select:user_id', 'from:mail_accounts', 'param:4:int', 'eq:id', 'where', 'max:1', 'execute', 'fetch', 'close'],
			actual: $this->calls
		);
		self::assertFalse($this->source(rows: [['user_id' => 'piet']])->ownsAccount(accountId: 4, userId: 'sanne'));
		self::assertFalse($this->source(rows: [['user_id' => null]])->ownsAccount(accountId: 4, userId: ''));
		self::assertFalse($this->source(rows: [])->ownsAccount(accountId: 4, userId: 'sanne'));
	}//end testOwnershipIsTheStoredOwner()

	/**
	 * A blank input asks the database nothing.
	 *
	 * @return void
	 */
	public function testABlankInputAsksNothing(): void {
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->expects(self::never())->method('getQueryBuilder');
		$source = new MailMessageSource(db: $db, logger: $this->createMock(originalClassName: LoggerInterface::class));

		self::assertSame(
			expected: [[], false, false, ''],
			actual: [$source->accountsOf(userId: ''), $source->ownsAccount(accountId: 0, userId: 'sanne'), $source->ownsAccount(accountId: 4, userId: ''), $source->ownerOf(accountId: -1)]
		);
	}//end testABlankInputAsksNothing()

	/**
	 * A failed read answers the empty answer and logs a warning naming the read.
	 *
	 * @return void
	 */
	public function testAFailedReadAnswersEmptyAndWarns(): void {
		$messages = [];
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message) use (&$messages): void {
				$messages[] = $message;
			}
		);
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willThrowException(new RuntimeException('no table'));
		$source = new MailMessageSource(db: $db, logger: $logger);

		self::assertSame(
			expected: [[], [], false, ''],
			actual: [$source->accountsOf(userId: 'sanne'), $source->allAccounts(), $source->ownsAccount(accountId: 4, userId: 'sanne'), $source->ownerOf(accountId: 4)]
		);
		self::assertSame(
			expected: [
				'Dossiq: listing Mail accounts failed: no table',
				'Dossiq: listing every Mail account failed: no table',
				'Dossiq: Mail account ownership lookup failed: no table',
				'Dossiq: reading the Mail account owner failed: no table',
			],
			actual: $messages
		);
	}//end testAFailedReadAnswersEmptyAndWarns()

	/**
	 * A source over a query builder that records its calls and answers the given rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The rows the query answers.
	 *
	 * @return MailMessageSource The source.
	 */
	private function source(array $rows): MailMessageSource {
		$this->calls = [];
		$calls = &$this->calls;
		$expr = $this->createMock(originalClassName: IExpressionBuilder::class);
		$expr->method('eq')->willReturnCallback(
			static function (string $column) use (&$calls): string {
				$calls[] = 'eq:' . $column;

				return $column . ' = ?';
			}
		);
		$result = $this->createMock(originalClassName: IResult::class);
		$result->method('fetch')->willReturnCallback(
			static function () use (&$calls, &$rows): array|false {
				$calls[] = 'fetch';

				return (array_shift($rows) ?? false);
			}
		);
		$result->method('closeCursor')->willReturnCallback(
			static function () use (&$calls): bool {
				$calls[] = 'close';

				return true;
			}
		);
		$qb = $this->createMock(originalClassName: IQueryBuilder::class);
		$record = static function (string $call) use (&$calls, $qb): \Closure {
			return static function (...$arguments) use (&$calls, $call, $qb): IQueryBuilder {
				$calls[] = $call . (in_array($call, ['select', 'from', 'orderBy', 'max'], true) === true ? ':' . trim(implode(separator: $call === 'select' ? ',' : ' ', array: array_map('strval', $arguments))) : '');

				return $qb;
			};
		};
		$qb->method('select')->willReturnCallback($record('select'));
		$qb->method('from')->willReturnCallback($record('from'));
		$qb->method('where')->willReturnCallback($record('where'));
		$qb->method('orderBy')->willReturnCallback($record('orderBy'));
		$qb->method('setMaxResults')->willReturnCallback($record('max'));
		$qb->method('expr')->willReturn($expr);
		$qb->method('createNamedParameter')->willReturnCallback(
			static function (mixed $value, mixed $type = null) use (&$calls): string {
				$calls[] = 'param:' . $value . ($type === IQueryBuilder::PARAM_INT ? ':int' : '');

				return ':p';
			}
		);
		$qb->method('executeQuery')->willReturnCallback(
			static function () use (&$calls, $result): IResult {
				$calls[] = 'execute';

				return $result;
			}
		);
		$db = $this->createMock(originalClassName: IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);

		return new MailMessageSource(db: $db, logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end source()
}//end class
