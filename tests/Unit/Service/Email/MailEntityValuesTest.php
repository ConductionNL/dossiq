<?php

/**
 * MailEntityValues: getters off Mail's entities, a synchronisation event and attachment rows.
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
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/MailCollaboratorLog.php';

/**
 * Getters off Mail's entities, a synchronisation event, and attachment rows.
 *
 * @covers \OCA\Dossiq\Service\Email\MailEntityValues
 */
class MailEntityValuesTest extends TestCase {

	/**
	 * A getter that is missing, throws or answers null gives the fallback; one that answers gives its value.
	 *
	 * @return void
	 */
	public function testAGetterFallsBackWhenItCannotAnswer(): void {
		$log = new MailCollaboratorLog();
		$values = new MailEntityValues(logger: $log);
		$entity = new class() {
			/**
			 * Stand-in for Mail's getId().
			 *
			 * @return int
			 */
			public function getId(): int {
				return 7;
			}

			/**
			 * Stand-in for Mail's getName().
			 *
			 * @return ?string
			 */
			public function getName(): ?string {
				return null;
			}

			/**
			 * Stand-in for Mail's getUid().
			 *
			 * @return int
			 */
			public function getUid(): int {
				throw new RuntimeException('gone');
			}
		};

		self::assertSame(expected: 7, actual: $values->identifierOf(entity: $entity));
		self::assertSame(expected: '', actual: $values->nameOf(entity: $entity));
		self::assertSame(expected: 'none', actual: $values->call(entity: $entity, getter: ['getSubject', 'none']));
		self::assertSame(expected: 0, actual: $values->call(entity: $entity, getter: ['getUid', 0]));
		self::assertSame(expected: [['debug', 'Dossiq: a Mail getter threw']], actual: $log->records);
	}//end testAGetterFallsBackWhenItCannotAnswer()

	/**
	 * An event without a readable account or mailbox is null with a warning; a readable one gives its rows.
	 *
	 * @return void
	 */
	public function testASynchronisationEventIsReadIntoRows(): void {
		$log = new MailCollaboratorLog();
		$values = new MailEntityValues(logger: $log);
		self::assertNull(actual: $values->synchronisation(event: new \stdClass()));
		self::assertSame(expected: [['warning', 'Dossiq: a Mail synchronisation event could not be read']], actual: $log->records);

		$message = new class() {
			/**
			 * Stand-in for Mail's getId().
			 *
			 * @return int
			 */
			public function getId(): int {
				return 3;
			}

			/**
			 * Stand-in for Mail's getUid().
			 *
			 * @return int
			 */
			public function getUid(): int {
				return 30;
			}

			/**
			 * Stand-in for Mail's getMessageId().
			 *
			 * @return string
			 */
			public function getMessageId(): string {
				return '<a@b>';
			}

			/**
			 * Stand-in for Mail's getSubject().
			 *
			 * @return string
			 */
			public function getSubject(): string {
				return 'Hello';
			}
		};
		$account = new class() {
			/**
			 * Stand-in for Mail's getId().
			 *
			 * @return int
			 */
			public function getId(): int {
				return 1;
			}
		};
		$mailbox = new class() {
			/**
			 * Stand-in for Mail's getName().
			 *
			 * @return string
			 */
			public function getName(): string {
				return 'INBOX';
			}
		};
		$event = new class($account, $mailbox, [$message, 'not an entity']) {
			/**
			 * Constructor.
			 *
			 * @param object $account The account.
			 * @param object $mailbox The mailbox.
			 * @param array $messages The messages.
			 */
			public function __construct(private object $account, private object $mailbox, private array $messages) {
			}

			/**
			 * Stand-in for Mail's getAccount().
			 *
			 * @return object
			 */
			public function getAccount(): object {
				return $this->account;
			}

			/**
			 * Stand-in for Mail's getMailbox().
			 *
			 * @return object
			 */
			public function getMailbox(): object {
				return $this->mailbox;
			}

			/**
			 * Stand-in for Mail's getMessages().
			 *
			 * @return array
			 */
			public function getMessages(): array {
				return $this->messages;
			}
		};

		self::assertSame(
			expected: [
				'accountId' => 1,
				'mailbox' => 'INBOX',
				'messages' => [['id' => 3, 'uid' => 30, 'messageId' => '<a@b>', 'subject' => 'Hello', 'sentAt' => '']],
			],
			actual: $values->synchronisation(event: $event)
		);
		self::assertSame(expected: [], actual: $values->eventMessages(rows: 'not a list'));
	}//end testASynchronisationEventIsReadIntoRows()

	/**
	 * Attachment rows take either key spelling; anything that is not a list of arrays is none.
	 *
	 * @return void
	 */
	public function testAttachmentRowsTakeEitherSpelling(): void {
		$values = new MailEntityValues(logger: new MailCollaboratorLog());
		self::assertSame(expected: [], actual: $values->attachmentRows(found: null));
		self::assertSame(
			expected: [
				['name' => 'a.pdf', 'mimeType' => 'application/pdf', 'size' => 10],
				['name' => 'b.txt', 'mimeType' => 'text/plain', 'size' => 0],
			],
			actual: $values->attachmentRows(
				found: [
					['fileName' => 'a.pdf', 'mime' => 'application/pdf', 'size' => '10'],
					'skipped',
					['name' => 'b.txt', 'mimeType' => 'text/plain'],
				]
			)
		);
	}//end testAttachmentRowsTakeEitherSpelling()
}//end class
