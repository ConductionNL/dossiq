<?php

/**
 * MailMessageCalls: the calls on Mail's message manager, answering and failing.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\MailEntityValues;
use OCA\Dossiq\Service\Email\MailMessageCalls;
use OCA\Dossiq\Service\Email\ThreadingLookup;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/MailCollaboratorLog.php';

/**
 * The four calls on Mail's manager, answering and failing.
 *
 * @covers \OCA\Dossiq\Service\Email\MailMessageCalls
 * @uses \OCA\Dossiq\Service\Email\MailEntityValues
 * @uses \OCA\Dossiq\Service\Email\MailSourceReader
 * @uses \OCA\Dossiq\Service\Email\ThreadingLookup
 */
class MailMessageCallsTest extends TestCase {

	/**
	 * A manager that answers per method, or throws when the answer is a Throwable.
	 *
	 * @param array<string, mixed> $answers The answers.
	 *
	 * @return ThreadingLookup The account with the manager.
	 */
	private static function call(array $answers): ThreadingLookup {
		$manager = new class($answers) {
			/** @var array<int, array<int, mixed>> */
			public array $calls = [];

			public function __construct(private array $answers) {
			}

			private function answer(string $method, array $args): mixed {
				$this->calls[] = [$method, ...$args];
				$answer = ($this->answers[$method] ?? null);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}

			public function getSource(object $account, string $mailbox, int $uid): mixed {
				return $this->answer('getSource', [$mailbox, $uid]);
			}

			public function getByMessageId(object $account, string $messageId): mixed {
				return $this->answer('getByMessageId', [$messageId]);
			}

			public function moveMessage(object $account, string $mailbox, int $uid, object $target, string $folder): mixed {
				return $this->answer('moveMessage', [$mailbox, $uid, $folder]);
			}

			public function getMailAttachments(object $account, string $mailbox, int $uid): mixed {
				return $this->answer('getMailAttachments', [$mailbox, $uid]);
			}
		};

		return new ThreadingLookup(account: new \stdClass(), manager: $manager);
	}//end call()

	/**
	 * The calls.
	 *
	 * @param MailCollaboratorLog $log The log.
	 *
	 * @return MailMessageCalls
	 */
	private static function calls(MailCollaboratorLog $log): MailMessageCalls {
		return new MailMessageCalls(values: new MailEntityValues(logger: $log), logger: $log);
	}//end calls()

	/**
	 * Each call answers what Mail answered.
	 *
	 * @return void
	 */
	public function testEachCallAnswersWhatMailAnswered(): void {
		$call = self::call(['getSource' => 'raw', 'getByMessageId' => [1], 'getMailAttachments' => [['name' => 'a', 'size' => 1]]]);
		$calls = self::calls(new MailCollaboratorLog());

		self::assertSame('raw', $calls->source(call: $call, clientFactory: static fn (): ?object => null, message: [1, 'INBOX', 5]));
		self::assertTrue($calls->holds(call: $call, accountId: 1, messageId: 'a@b'));
		self::assertTrue($calls->move(call: $call, move: [1, 'INBOX', 5, 'Done']));
		self::assertSame([['name' => 'a', 'mimeType' => '', 'size' => 1]], $calls->attachments(call: $call, message: [1, 'INBOX', 5]));
		self::assertSame(
			[['getSource', 'INBOX', 5], ['getByMessageId', 'a@b'], ['moveMessage', 'INBOX', 5, 'Done'], ['getMailAttachments', 'INBOX', 5]],
			$call->manager->calls
		);
	}//end testEachCallAnswersWhatMailAnswered()

	/**
	 * A call that throws gives the empty or negative answer, with a warning each.
	 *
	 * @return void
	 */
	public function testAFailedCallGivesTheEmptyAnswer(): void {
		$boom = new RuntimeException('down');
		$call = self::call(['getSource' => $boom, 'getByMessageId' => $boom, 'moveMessage' => $boom, 'getMailAttachments' => $boom]);
		$log = new MailCollaboratorLog();
		$calls = self::calls($log);

		self::assertSame('', $calls->source(call: $call, clientFactory: static fn (): ?object => null, message: [1, 'INBOX', 5]));
		self::assertFalse($calls->holds(call: $call, accountId: 1, messageId: 'a@b'));
		self::assertFalse($calls->move(call: $call, move: [1, 'INBOX', 5, 'Done']));
		self::assertSame([], $calls->attachments(call: $call, message: [1, 'INBOX', 5]));
		self::assertSame(['warning', 'warning', 'warning', 'warning'], array_column($log->records, 0));
	}//end testAFailedCallGivesTheEmptyAnswer()

	/**
	 * An empty or non-list lookup answer is "no"; a non-string source is ''.
	 *
	 * @return void
	 */
	public function testAnEmptyAnswerIsNo(): void {
		$calls = self::calls(new MailCollaboratorLog());
		self::assertFalse($calls->holds(call: self::call(['getByMessageId' => []]), accountId: 1, messageId: 'a@b'));
		self::assertFalse($calls->holds(call: self::call(['getByMessageId' => 'yes']), accountId: 1, messageId: 'a@b'));
		self::assertSame('', $calls->source(call: self::call(['getSource' => 42]), clientFactory: static fn (): ?object => null, message: [1, 'INBOX', 5]));
	}//end testAnEmptyAnswerIsNo()
}//end class
