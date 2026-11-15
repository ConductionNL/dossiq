<?php

/**
 * Reading the Mail app's tables: accounts, ownership, the high-water mark, folders and messages.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\MailMessageSource;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Email\MailMessageSource
 */
class MailMessageSourceTest extends TestCase {

	/**
	 * The connection.
	 *
	 * @var IDBConnection&MockObject
	 */
	private IDBConnection $db;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Named parameters created, in order, as [value, type].
	 *
	 * @var array<int, array{0: mixed, 1: mixed}>
	 */
	private array $params = [];

	/**
	 * Fresh mocks.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->db = $this->createMock(IDBConnection::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->params = [];
	}//end setUp()

	/**
	 * Hand out a chainable query builder whose query answers the given rows, then false.
	 *
	 * @param array<int, mixed> $rows    The rows `fetch()` answers in turn.
	 * @param mixed             $one     What `fetchOne()` answers.
	 * @param string|null       $table   The table `from()` must be called with, when checked.
	 *
	 * @return IQueryBuilder&MockObject
	 */
	private function answering(array $rows, mixed $one = false, ?string $table = null): IQueryBuilder {
		$result = $this->createMock(IResult::class);
		$result->method('fetch')->willReturnOnConsecutiveCalls(...[...$rows, false]);
		$result->method('fetchOne')->willReturn($one);
		$result->expects($this->once())->method('closeCursor');

		$query = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'where', 'andWhere', 'orderBy', 'setMaxResults', 'join'] as $method) {
			$query->method($method)->willReturnSelf();
		}

		if ($table !== null) {
			$query->expects($this->once())->method('from')->with($table)->willReturnSelf();
		} else {
			$query->method('from')->willReturnSelf();
		}

		$query->method('createNamedParameter')->willReturnCallback(
			function (mixed $value, mixed $type = IQueryBuilder::PARAM_STR): string {
				$this->params[] = [$value, $type];
				return ':p' . (count($this->params) - 1);
			}
		);
		$query->method('expr')->willReturn($this->createMock(IExpressionBuilder::class));
		$functions = $this->createMock(IFunctionBuilder::class);
		$functions->method('max')->willReturn($this->createMock(IQueryFunction::class));
		$query->method('func')->willReturn($functions);
		$query->method('executeQuery')->willReturn($result);

		$this->db->method('getQueryBuilder')->willReturn($query);

		return $query;
	}//end answering()

	/**
	 * Make every query fail.
	 *
	 * @param string $warning The warning expected.
	 *
	 * @return void
	 */
	private function failing(string $warning): void {
		$this->db->method('getQueryBuilder')->willThrowException(new RuntimeException('no such table'));
		$this->logger->expects($this->once())->method('warning')->with($warning . 'no such table');
	}//end failing()

	/**
	 * The source under test.
	 *
	 * @return MailMessageSource
	 */
	private function source(): MailMessageSource {
		return new MailMessageSource(db: $this->db, logger: $this->logger);
	}//end source()

	public function testAccountsOfAUserAreTypedRowsOfThatUser(): void {
		$this->answering(rows: [['id' => '3', 'name' => 'Zaken', 'email' => 'zaken@gemeente.nl'], ['id' => 4]], table: 'mail_accounts');

		self::assertSame(
			[['id' => 3, 'name' => 'Zaken', 'email' => 'zaken@gemeente.nl'], ['id' => 4, 'name' => '', 'email' => '']],
			$this->source()->accountsOf(userId: 'jan')
		);
		self::assertSame([['jan', IQueryBuilder::PARAM_STR]], $this->params);
	}//end testAccountsOfAUserAreTypedRowsOfThatUser()

	public function testNoUserMeansNoAccountsAndNoQuery(): void {
		$this->db->expects($this->never())->method('getQueryBuilder');

		self::assertSame([], $this->source()->accountsOf(userId: ''));
		self::assertFalse($this->source()->ownsAccount(accountId: 3, userId: ''));
		self::assertFalse($this->source()->ownsAccount(accountId: 0, userId: 'jan'));
		self::assertSame('', $this->source()->ownerOf(accountId: -1));
		self::assertSame([], $this->source()->mailboxesOf(accountId: 0));
		self::assertSame([], $this->source()->listMailboxMessagesSince(accountId: 3, mailbox: '', sinceId: 0, limit: 10));
		self::assertSame([], $this->source()->listMailboxMessagesSince(accountId: 3, mailbox: 'INBOX', sinceId: 0, limit: 0));
	}//end testNoUserMeansNoAccountsAndNoQuery()

	public function testAllAccountsListsEveryAccount(): void {
		$this->answering(rows: [['id' => 1, 'name' => 'A', 'email' => 'a@x'], ['id' => 2, 'name' => 'B', 'email' => 'b@x']]);

		self::assertSame([1, 2], array_column($this->source()->allAccounts(), 'id'));
	}//end testAllAccountsListsEveryAccount()

	public function testAnAccountIsOwnedOnlyByItsStoredUser(): void {
		$this->answering(rows: [['user_id' => 'jan']]);
		self::assertTrue($this->source()->ownsAccount(accountId: 3, userId: 'jan'));
		self::assertSame([[3, IQueryBuilder::PARAM_INT]], $this->params);
	}//end testAnAccountIsOwnedOnlyByItsStoredUser()

	public function testAnAccountOfSomeoneElseOrNoneIsNotOwned(): void {
		$this->answering(rows: [['user_id' => 'piet']]);
		self::assertFalse($this->source()->ownsAccount(accountId: 3, userId: 'jan'));
	}//end testAnAccountOfSomeoneElseOrNoneIsNotOwned()

	public function testTheOwnerIsTheStoredUserOrNobody(): void {
		$this->answering(rows: []);
		self::assertSame('', $this->source()->ownerOf(accountId: 3));
	}//end testTheOwnerIsTheStoredUserOrNobody()

	public function testTheHighWaterMarkIsTheLargestMessageId(): void {
		$this->answering(rows: [], one: '9041');
		self::assertSame(9041, $this->source()->maxMessageId(accountId: 3));
		self::assertSame([[3, IQueryBuilder::PARAM_INT]], $this->params);
	}//end testTheHighWaterMarkIsTheLargestMessageId()

	public function testMessagesSinceAreTypedAndTheCursorIsBound(): void {
		$query = $this->answering(rows: [['id' => '12', 'uid' => 7, 'subject' => 'Re: Z-2026-0001', 'preview_text' => 'Hallo']]);
		$query->expects($this->once())->method('setMaxResults')->with(50)->willReturnSelf();

		self::assertSame(
			[['id' => 12, 'uid' => '7', 'subject' => 'Re: Z-2026-0001', 'preview' => 'Hallo']],
			$this->source()->listMessagesSince(accountId: 3, sinceId: 11, limit: 50)
		);
		self::assertSame([[3, IQueryBuilder::PARAM_INT], [11, IQueryBuilder::PARAM_INT]], $this->params);
	}//end testMessagesSinceAreTypedAndTheCursorIsBound()

	public function testFolderNamesSkipBlanks(): void {
		$this->answering(rows: [['name' => 'Archief'], ['name' => ''], ['name' => 'INBOX']], table: 'mail_mailboxes');

		self::assertSame(['Archief', 'INBOX'], $this->source()->mailboxesOf(accountId: 3));
	}//end testFolderNamesSkipBlanks()

	public function testFolderMessagesCarryTheirFolderAndBindIt(): void {
		$this->answering(rows: [['id' => 5, 'uid' => '88', 'message_id' => 'm@x', 'subject' => 's', 'sent_at' => 1700000000], ['id' => 6, 'name' => 'Zaken']]);

		$messages = $this->source()->listMailboxMessagesSince(accountId: 3, mailbox: 'Zaken', sinceId: 4, limit: 10);

		self::assertSame(['id' => 5, 'uid' => 88, 'mailbox' => 'Zaken', 'messageId' => 'm@x', 'subject' => 's', 'sentAt' => '1700000000'], $messages[0]);
		self::assertSame('Zaken', $messages[1]['mailbox']);
		self::assertSame([[3, IQueryBuilder::PARAM_INT], ['Zaken', IQueryBuilder::PARAM_STR], [4, IQueryBuilder::PARAM_INT]], $this->params);
	}//end testFolderMessagesCarryTheirFolderAndBindIt()

	public function testAFailingAccountListIsLoggedAndEmpty(): void {
		$this->failing(warning: 'Dossiq: listing Mail accounts failed: ');
		self::assertSame([], $this->source()->accountsOf(userId: 'jan'));
	}//end testAFailingAccountListIsLoggedAndEmpty()

	public function testAFailingOwnershipLookupIsLoggedAndRefuses(): void {
		$this->failing(warning: 'Dossiq: Mail account ownership lookup failed: ');
		self::assertFalse($this->source()->ownsAccount(accountId: 3, userId: 'jan'));
	}//end testAFailingOwnershipLookupIsLoggedAndRefuses()

	public function testAFailingHighWaterMarkIsLoggedAndZero(): void {
		$this->failing(warning: 'Dossiq: reading the Mail high-water mark failed: ');
		self::assertSame(0, $this->source()->maxMessageId(accountId: 3));
	}//end testAFailingHighWaterMarkIsLoggedAndZero()

	public function testAFailingFolderListingIsLoggedAndEmpty(): void {
		$this->failing(warning: 'Dossiq: listing the intake folder failed: ');
		self::assertSame([], $this->source()->listMailboxMessagesSince(accountId: 3, mailbox: 'INBOX', sinceId: 0, limit: 5));
	}//end testAFailingFolderListingIsLoggedAndEmpty()
}//end class
