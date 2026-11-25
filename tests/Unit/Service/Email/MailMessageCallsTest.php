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

			/**
			 * Constructor.
			 *
			 * @param array $answers The answers.
			 */
			public function __construct(private array $answers) {
			}

			/**
			 * Stand-in for Mail's answer().
			 *
			 * @param string $method The method.
			 * @param array $args The args.
			 * @return mixed
			 */
			private function answer(string $method, array $args): mixed {
				$this->calls[] = [$method, ...$args];
				$answer = ($this->answers[$method] ?? null);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}

			/**
			 * Stand-in for Mail's getSource().
			 *
			 * @param object $account The account.
			 * @param string $mailbox The mailbox.
			 * @param int $uid The uid.
			 * @return mixed
			 */
			public function getSource(object $account, string $mailbox, int $uid): mixed {
				return $this->answer(method: 'getSource', args: [$mailbox, $uid]);
			}

			/**
			 * Stand-in for Mail's getByMessageId().
			 *
			 * @param object $account The account.
			 * @param string $messageId The messageId.
			 * @return mixed
			 */
			public function getByMessageId(object $account, string $messageId): mixed {
				return $this->answer(method: 'getByMessageId', args: [$messageId]);
			}

			/**
			 * Stand-in for Mail's moveMessage().
			 *
			 * @param object $account The account.
			 * @param string $mailbox The mailbox.
			 * @param int $uid The uid.
			 * @param object $target The target.
			 * @param string $folder The folder.
			 * @return mixed
			 */
			public function moveMessage(object $account, string $mailbox, int $uid, object $target, string $folder): mixed {
				return $this->answer(method: 'moveMessage', args: [$mailbox, $uid, $folder]);
			}

			/**
			 * Stand-in for Mail's getMailAttachments().
			 *
			 * @param object $account The account.
			 * @param string $mailbox The mailbox.
			 * @param int $uid The uid.
			 * @return mixed
			 */
			public function getMailAttachments(object $account, string $mailbox, int $uid): mixed {
				return $this->answer(method: 'getMailAttachments', args: [$mailbox, $uid]);
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
		$call = self::call(answers: ['getSource' => 'raw', 'getByMessageId' => [1], 'getMailAttachments' => [['name' => 'a', 'size' => 1]]]);
		$calls = self::calls(log: new MailCollaboratorLog());

		self::assertSame(expected: 'raw', actual: $calls->source(call: $call, clientFactory: static fn (): ?object => null, message: [1, 'INBOX', 5]));
		self::assertTrue(condition: $calls->holds(call: $call, accountId: 1, messageId: 'a@b'));
		self::assertTrue(condition: $calls->move(call: $call, move: [1, 'INBOX', 5, 'Done']));
		self::assertSame(expected: [['name' => 'a', 'mimeType' => '', 'size' => 1]], actual: $calls->attachments(call: $call, message: [1, 'INBOX', 5]));
		self::assertSame(
			expected: [['getSource', 'INBOX', 5], ['getByMessageId', 'a@b'], ['moveMessage', 'INBOX', 5, 'Done'], ['getMailAttachments', 'INBOX', 5]],
			actual: $call->manager->calls
		);
	}//end testEachCallAnswersWhatMailAnswered()

	/**
	 * A call that throws gives the empty or negative answer, with a warning each.
	 *
	 * @return void
	 */
	public function testAFailedCallGivesTheEmptyAnswer(): void {
		$boom = new RuntimeException('down');
		$call = self::call(answers: ['getSource' => $boom, 'getByMessageId' => $boom, 'moveMessage' => $boom, 'getMailAttachments' => $boom]);
		$log = new MailCollaboratorLog();
		$calls = self::calls(log: $log);

		self::assertSame(expected: '', actual: $calls->source(call: $call, clientFactory: static fn (): ?object => null, message: [1, 'INBOX', 5]));
		self::assertFalse(condition: $calls->holds(call: $call, accountId: 1, messageId: 'a@b'));
		self::assertFalse(condition: $calls->move(call: $call, move: [1, 'INBOX', 5, 'Done']));
		self::assertSame(expected: [], actual: $calls->attachments(call: $call, message: [1, 'INBOX', 5]));
		self::assertSame(expected: ['warning', 'warning', 'warning', 'warning'], actual: array_column($log->records, 0));
	}//end testAFailedCallGivesTheEmptyAnswer()

	/**
	 * An empty or non-list lookup answer is "no"; a non-string source is ''.
	 *
	 * @return void
	 */
	public function testAnEmptyAnswerIsNo(): void {
		$calls = self::calls(log: new MailCollaboratorLog());
		$none = self::call(answers: ['getByMessageId' => []]);
		$notAList = self::call(answers: ['getByMessageId' => 'yes']);
		$notText = self::call(answers: ['getSource' => 42]);
		self::assertFalse(condition: $calls->holds(call: $none, accountId: 1, messageId: 'a@b'));
		self::assertFalse(condition: $calls->holds(call: $notAList, accountId: 1, messageId: 'a@b'));
		self::assertSame(
			expected: '',
			actual: $calls->source(call: $notText, clientFactory: static fn (): ?object => null, message: [1, 'INBOX', 5])
		);
	}//end testAnEmptyAnswerIsNo()
}//end class
