<?php

/**
 * MailOutboxDelivery: one message through Mail's outbox.
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
use OCA\Dossiq\Service\Email\MailOutboxDelivery;
use OCA\Dossiq\Service\Email\OutboundState;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__ . '/MailCollaboratorLog.php';

/**
 * One message through Mail's outbox: taken, sent, queued or refused.
 *
 * @covers \OCA\Dossiq\Service\Email\MailOutboxDelivery
 * @uses \OCA\Dossiq\Service\Email\MailEntityValues
 * @uses \OCA\Dossiq\Service\Email\OutboxSend
 */
class MailOutboxDeliveryTest extends TestCase {

	/**
	 * An outbox that saves as row id 9 and leaves the given status, or throws on either verb.
	 *
	 * @param mixed $save What saveMessage throws, or null to save.
	 * @param mixed $send The status the sent row carries, or a Throwable sendMessage throws.
	 *
	 * @return object
	 */
	private static function outbox(mixed $save, mixed $send): object {
		return new class($save, $send) {
			/** @var array<int, mixed> */
			public array $saved = [];

			/**
			 * Constructor.
			 *
			 * @param mixed $save The save.
			 * @param mixed $send The send.
			 */
			public function __construct(private mixed $save, private mixed $send) {
			}

			/**
			 * Stand-in for Mail's saveMessage().
			 *
			 * @param object $account The account.
			 * @param object $local The local.
			 * @param array $to The to.
			 * @param array $cc The cc.
			 * @param array $bcc The bcc.
			 * @param array $attachments The attachments.
			 * @return object
			 */
			public function saveMessage(object $account, object $local, array $to, array $cc, array $bcc, array $attachments): object {
				if ($this->save instanceof \Throwable) {
					throw $this->save;
				}

				$this->saved = [$local, $to, $attachments];
				return new class() {
					/**
					 * Stand-in for Mail's getId().
					 *
					 * @return int
					 */
					public function getId(): int {
						return 9;
					}
				};
			}

			/**
			 * Stand-in for Mail's sendMessage().
			 *
			 * @param object $saved The saved.
			 * @param object $account The account.
			 * @return object
			 */
			public function sendMessage(object $saved, object $account): object {
				if ($this->send instanceof \Throwable) {
					throw $this->send;
				}

				return new class($this->send) {
					/**
					 * Constructor.
					 *
					 * @param int $status The status.
					 */
					public function __construct(private int $status) {
					}

					/**
					 * Stand-in for Mail's getStatus().
					 *
					 * @return int
					 */
					public function getStatus(): int {
						return $this->status;
					}
				};
			}
		};
	}//end outbox()

	/**
	 * The delivery, building a plain row per message.
	 *
	 * @param MailCollaboratorLog $log The log.
	 *
	 * @return MailOutboxDelivery
	 */
	private static function delivery(MailCollaboratorLog $log): MailOutboxDelivery {
		return new MailOutboxDelivery(
			values: new MailEntityValues(logger: $log),
			localMessage: static fn (int $accountId, array $message): object => (object)['account' => $accountId, 'subject' => $message['subject']],
			logger: $log
		);
	}//end delivery()

	/**
	 * A taken and processed message is sent, with its row id, recipients and cloud attachments.
	 *
	 * @return void
	 */
	public function testATakenMessageIsSent(): void {
		$outbox = self::outbox(save: null, send: 12);
		$answer = self::delivery(log: new MailCollaboratorLog())->deliver(
			through: [4, new \stdClass(), $outbox],
			message: ['to' => ['a@b.nl'], 'subject' => 'Hi', 'attachments' => ['/Case/a.pdf']]
		);

		self::assertSame(expected: ['state' => OutboundState::SENT, 'outboxId' => 9], actual: $answer);
		self::assertEquals(expected: (object)['account' => 4, 'subject' => 'Hi'], actual: $outbox->saved[0]);
		self::assertSame(expected: [['email' => 'a@b.nl']], actual: $outbox->saved[1]);
		self::assertSame(expected: [['type' => 'cloud', 'fileName' => '/Case/a.pdf']], actual: $outbox->saved[2]);
	}//end testATakenMessageIsSent()

	/**
	 * A refused save is unavailable; a failed send stays queued with its row id.
	 *
	 * @return void
	 */
	public function testARefusedOrUnsentMessage(): void {
		$log = new MailCollaboratorLog();
		$delivery = self::delivery(log: $log);
		$message = ['to' => [], 'subject' => 'Hi'];

		self::assertSame(
			expected: ['state' => OutboundState::UNAVAILABLE, 'outboxId' => null],
			actual: $delivery->deliver(through: [4, new \stdClass(), self::outbox(save: new RuntimeException('full'), send: 12)], message: $message)
		);
		self::assertSame(
			expected: ['state' => OutboundState::QUEUED, 'outboxId' => 9],
			actual: $delivery->deliver(through: [4, new \stdClass(), self::outbox(save: null, send: new RuntimeException('smtp'))], message: $message)
		);
		self::assertSame(expected: ['warning', 'warning'], actual: array_column($log->records, 0));
	}//end testARefusedOrUnsentMessage()

	/**
	 * Mail's status numbers map to the delivery states.
	 *
	 * @return void
	 */
	public function testStatusNumbersMapToStates(): void {
		self::assertSame(expected: OutboundState::SENT, actual: MailOutboxDelivery::stateOfStatus(status: 12));
		self::assertSame(expected: OutboundState::SENT_NOT_FILED, actual: MailOutboxDelivery::stateOfStatus(status: 11));
		self::assertSame(expected: OutboundState::QUEUED, actual: MailOutboxDelivery::stateOfStatus(status: -1));
		self::assertSame(expected: OutboundState::QUEUED, actual: $this->sentWithStatus(status: 3));
	}//end testStatusNumbersMapToStates()

	/**
	 * The state of a message Mail left with this status.
	 *
	 * @param int $status The status.
	 *
	 * @return string
	 */
	private function sentWithStatus(int $status): string {
		return self::delivery(log: new MailCollaboratorLog())->deliver(
			through: [4, new \stdClass(), self::outbox(save: null, send: $status)],
			message: ['subject' => '']
		)['state'];
	}//end sentWithStatus()
}//end class
