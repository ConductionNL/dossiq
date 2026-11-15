<?php

/**
 * NextcloudMailGateway tests against stand-in Mail services.
 *
 * With Nextcloud Mail answering through the container, the gateway reads a
 * message source, moves a message, lists its attachments and unpacks a
 * synchronisation event, and turns every failure of Mail into an empty or
 * negative answer instead of an exception.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\Email\MailMessageSource;
use OCA\Dossiq\Service\Email\NextcloudMailGateway;
use OCA\Mail\Events\NewMessagesSynchronized;
use OCP\App\IAppManager;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\AbstractLogger;
use RuntimeException;

require_once __DIR__ . '/../../../Support/Mail/NewMessagesSynchronized.php';

/**
 * The gateway's reads and moves through Mail's services, and their failures.
 *
 * @covers \OCA\Dossiq\Service\Email\NextcloudMailGateway
 */
class NextcloudMailGatewayServicesTest extends TestCase {

	/**
	 * Log records as [level, message, context].
	 *
	 * @var array<int, array{0: string, 1: string, 2: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * Calls the mail manager received, as [method, ...arguments].
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $managerCalls = [];

	/**
	 * A plain Mail entity answering getId() and getName().
	 *
	 * @param int    $id   The id.
	 * @param string $name The name.
	 *
	 * @return object
	 */
	private static function entity(int $id, string $name = ''): object {
		return new class($id, $name) {
			public function __construct(private int $id, private string $name) {
			}

			public function getId(): int {
				return $this->id;
			}

			public function getName(): string {
				return $this->name;
			}
		};
	}//end entity()

	/**
	 * A mail manager answering, or throwing, per method.
	 *
	 * @param array<string, mixed> $answers What each method answers, or a Throwable it throws.
	 *
	 * @return object
	 */
	private function manager(array $answers): object {
		$calls = &$this->managerCalls;
		return new class($answers, $calls) {
			/** @var array<int, array<int, mixed>> */
			private array $calls;

			/**
			 * @param array<string, mixed>          $answers The answers.
			 * @param array<int, array<int, mixed>> $calls   The record.
			 */
			public function __construct(private array $answers, array &$calls) {
				$this->calls = &$calls;
			}

			/**
			 * @param string            $method The method.
			 * @param array<int, mixed> $args   The arguments.
			 */
			private function answer(string $method, array $args): mixed {
				$this->calls[] = [$method, ...$args];
				$answer = ($this->answers[$method] ?? null);
				if ($answer instanceof \Throwable) {
					throw $answer;
				}

				return $answer;
			}

			public function getSource(object $account, string $mailbox, int $uid): mixed {
				return $this->answer('getSource', [$account, $mailbox, $uid]);
			}

			public function moveMessage(object $account, string $mailbox, int $uid, object $target, string $folder): mixed {
				return $this->answer('moveMessage', [$account, $mailbox, $uid, $target, $folder]);
			}

			public function getMailAttachments(object $account, string $mailbox, int $uid): mixed {
				return $this->answer('getMailAttachments', [$account, $mailbox, $uid]);
			}
		};
	}//end manager()

	/**
	 * A gateway whose container serves the given Mail services.
	 *
	 * @param array<string, mixed> $services Service by class name, or a Throwable to throw.
	 * @param bool|\Throwable      $installed Whether Mail is installed, or what asking throws.
	 *
	 * @return NextcloudMailGateway
	 */
	private function gateway(array $services, bool|\Throwable $installed = true): NextcloudMailGateway {
		$appManager = $this->createMock(IAppManager::class);
		if ($installed instanceof \Throwable) {
			$appManager->method('isInstalled')->willThrowException($installed);
		} else {
			$appManager->method('isInstalled')->with('mail')->willReturn($installed);
		}

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static function (string $name) use ($services): mixed {
			$service = ($services[$name] ?? null);
			if ($service instanceof \Throwable) {
				throw $service;
			}

			if ($service === null) {
				throw new RuntimeException('no ' . $name);
			}

			return $service;
		});

		$tables = $this->createMock(MailMessageSource::class);
		$tables->method('allAccounts')->willReturn([['id' => 3, 'email' => 'zaken@gemeente.example']]);
		$tables->method('mailboxesOf')->willReturnCallback(static fn (int $accountId): array => [['account' => $accountId, 'name' => 'INBOX']]);
		$tables->method('listMailboxMessagesSince')->willReturnCallback(
			static fn (int $accountId, string $mailbox, int $sinceId, int $limit): array => [compact('accountId', 'mailbox', 'sinceId', 'limit')]
		);

		$logged = &$this->logged;
		$logger = new class($logged) extends AbstractLogger {
			/** @var array<int, array{0: string, 1: string, 2: array<string, mixed>}> */
			private array $records;

			/**
			 * @param array<int, array{0: string, 1: string, 2: array<string, mixed>}> $records The record.
			 */
			public function __construct(array &$records) {
				$this->records = &$records;
			}

			/**
			 * @param mixed                $level   The level.
			 * @param string|\Stringable   $message The message.
			 * @param array<string, mixed> $context The context.
			 */
			public function log($level, string|\Stringable $message, array $context = []): void {
				$this->records[] = [(string)$level, (string)$message, $context];
			}
		};

		return new NextcloudMailGateway(appManager: $appManager, container: $container, mailTables: $tables, logger: $logger);
	}//end gateway()

	/**
	 * An account service that finds account 3, throws for 4 and answers nothing else.
	 *
	 * @param object $account The account it finds.
	 *
	 * @return object
	 */
	private static function accounts(object $account): object {
		return new class($account) {
			public function __construct(private object $account) {
			}

			public function findById(int $id): mixed {
				if ($id === 4) {
					throw new RuntimeException('account table locked');
				}

				return ($id === 3 ? $this->account : 'not an account');
			}
		};
	}//end accounts()

	/**
	 * Services with account 3 and the given mail manager.
	 *
	 * @param object $account The account.
	 * @param object $manager The mail manager.
	 *
	 * @return array<string, object>
	 */
	private static function services(object $account, object $manager): array {
		return [
			'OCA\\Mail\\Service\\AccountService' => self::accounts($account),
			'OCA\\Mail\\Service\\MailManager' => $manager,
		];
	}//end services()

	/**
	 * The messages logged at a level.
	 *
	 * @param string $level The level.
	 *
	 * @return array<int, string>
	 */
	private function messages(string $level): array {
		return array_values(array_map(
			static fn (array $r): string => $r[1],
			array_filter($this->logged, static fn (array $r): bool => $r[0] === $level)
		));
	}//end messages()

	/**
	 * With Mail installed, accounts, mailboxes and messages come from Mail's tables.
	 *
	 * @return void
	 */
	public function testListingsComeFromMailsTables(): void {
		$gateway = $this->gateway([]);

		self::assertSame([['id' => 3, 'email' => 'zaken@gemeente.example']], $gateway->accounts());
		self::assertSame([['account' => 3, 'name' => 'INBOX']], $gateway->mailboxes(3));
		self::assertSame([['accountId' => 3, 'mailbox' => 'INBOX', 'sinceId' => 0, 'limit' => 25]], $gateway->messages(3, 'INBOX', 25));
	}//end testListingsComeFromMailsTables()

	/**
	 * A failing installed-check reads as Mail being absent.
	 *
	 * @return void
	 */
	public function testAFailingInstalledCheckIsUnavailable(): void {
		$gateway = $this->gateway([], new RuntimeException('app store down'));

		self::assertFalse($gateway->isAvailable());
		self::assertSame([], $gateway->accounts());
		// Each of the two calls asked, and each failure was logged.
		self::assertSame(
			['Dossiq: could not ask whether the Mail app is installed', 'Dossiq: could not ask whether the Mail app is installed'],
			$this->messages('debug')
		);
	}//end testAFailingInstalledCheckIsUnavailable()

	/**
	 * The source is read for the account, mailbox and uid; a failure or a non-string is empty.
	 *
	 * @return void
	 */
	public function testTheSourceIsReadOrEmpty(): void {
		$account = self::entity(3);
		self::assertSame("From: a@b\r\n\r\nHoi", $this->gateway(self::services($account, $this->manager(['getSource' => "From: a@b\r\n\r\nHoi"])))->source(3, 'INBOX', 41));
		self::assertSame(['getSource', $account, 'INBOX', 41], $this->managerCalls[0]);

		self::assertSame('', $this->gateway(self::services($account, $this->manager(['getSource' => null])))->source(3, 'INBOX', 41));
		self::assertSame('', $this->gateway(self::services($account, $this->manager(['getSource' => new RuntimeException('imap gone')])))->source(3, 'INBOX', 41));
		self::assertSame(['Dossiq: reading a message source failed'], $this->messages('warning'));
	}//end testTheSourceIsReadOrEmpty()

	/**
	 * An unknown, unreadable or missing account reads nothing, and its failure is logged.
	 *
	 * @return void
	 */
	public function testAnAccountThatCannotBeFoundReadsNothing(): void {
		$gateway = $this->gateway(self::services(self::entity(3), $this->manager(['getSource' => 'x'])));

		self::assertSame('', $gateway->source(0, 'INBOX', 1));
		self::assertSame('', $gateway->source(4, 'INBOX', 1));
		self::assertSame('', $gateway->source(5, 'INBOX', 1));
		self::assertSame([], $this->managerCalls);
		self::assertSame(['Dossiq: the configured intake account could not be read'], $this->messages('warning'));
	}//end testAnAccountThatCannotBeFoundReadsNothing()

	/**
	 * A Mail service the container cannot resolve, or that is not an object, makes the read empty.
	 *
	 * @return void
	 */
	public function testAnUnresolvableServiceReadsNothing(): void {
		$account = self::entity(3);
		self::assertSame('', $this->gateway(['OCA\\Mail\\Service\\AccountService' => self::accounts($account), 'OCA\\Mail\\Service\\MailManager' => new RuntimeException('not booted')])->source(3, 'INBOX', 1));
		self::assertSame(['Dossiq: a Nextcloud Mail service could not be resolved, so intake is unavailable'], $this->messages('warning'));

		self::assertSame([], $this->gateway(['OCA\\Mail\\Service\\AccountService' => self::accounts($account), 'OCA\\Mail\\Service\\MailManager' => 'a string'])->attachments(3, 'INBOX', 1));
	}//end testAnUnresolvableServiceReadsNothing()

	/**
	 * A message moves within its account to the target folder; a failing move answers no.
	 *
	 * @return void
	 */
	public function testAMessageMovesWithinItsAccount(): void {
		$account = self::entity(3);
		self::assertTrue($this->gateway(self::services($account, $this->manager([])))->moveMessage(3, 'INBOX', 41, 'Dossiq/Verwerkt'));
		self::assertSame(['moveMessage', $account, 'INBOX', 41, $account, 'Dossiq/Verwerkt'], $this->managerCalls[0]);

		self::assertFalse($this->gateway(self::services($account, $this->manager(['moveMessage' => new RuntimeException('no such folder')])))->moveMessage(3, 'INBOX', 41, 'Nope'));
		self::assertSame(['Dossiq: moving a message between folders failed'], $this->messages('warning'));
		self::assertFalse($this->gateway(self::services($account, $this->manager([])))->moveMessage(5, 'INBOX', 41, 'Dossiq/Verwerkt'));
	}//end testAMessageMovesWithinItsAccount()

	/**
	 * Attachments are listed by name, type and size, under either of Mail's key names; rows that are not arrays are skipped.
	 *
	 * @return void
	 */
	public function testAttachmentsAreListedUnderEitherKeyName(): void {
		$account = self::entity(3);
		$found = [
			['fileName' => 'aanvraag.pdf', 'mime' => 'application/pdf', 'size' => '2048'],
			['name' => 'foto.jpg', 'mimeType' => 'image/jpeg'],
			'not an attachment',
		];

		self::assertSame(
			[
				['name' => 'aanvraag.pdf', 'mimeType' => 'application/pdf', 'size' => 2048],
				['name' => 'foto.jpg', 'mimeType' => 'image/jpeg', 'size' => 0],
			],
			$this->gateway(self::services($account, $this->manager(['getMailAttachments' => $found])))->attachments(3, 'INBOX', 41)
		);
		self::assertSame(['getMailAttachments', $account, 'INBOX', 41], $this->managerCalls[0]);
	}//end testAttachmentsAreListedUnderEitherKeyName()

	/**
	 * A failing or shapeless attachment answer is no attachments.
	 *
	 * @return void
	 */
	public function testAFailingAttachmentReadIsNone(): void {
		$account = self::entity(3);
		self::assertSame([], $this->gateway(self::services($account, $this->manager(['getMailAttachments' => new RuntimeException('imap gone')])))->attachments(3, 'INBOX', 41));
		self::assertSame(['Dossiq: reading attachments failed'], $this->messages('warning'));
		self::assertSame([], $this->gateway(self::services($account, $this->manager(['getMailAttachments' => 'x'])))->attachments(3, 'INBOX', 41));
	}//end testAFailingAttachmentReadIsNone()

	/**
	 * A synchronisation is unpacked into the account id, the mailbox name and its messages.
	 *
	 * @return void
	 */
	public function testASynchronisationIsUnpacked(): void {
		$message = new class {
			public function getId(): int {
				return 901;
			}

			public function getUid(): int {
				return 41;
			}

			public function getMessageId(): string {
				return '<abc@gemeente.example>';
			}

			public function getSubject(): ?string {
				return null;
			}

			public function getSentAt(): int {
				throw new RuntimeException('no date');
			}
		};
		$event = new NewMessagesSynchronized(self::entity(3), self::entity(8, 'INBOX'), [$message, 'not a message']);

		self::assertSame(
			[
				'accountId' => 3,
				'mailbox' => 'INBOX',
				'messages' => [['id' => 901, 'uid' => 41, 'messageId' => '<abc@gemeente.example>', 'subject' => '', 'sentAt' => '']],
			],
			$this->gateway([])->unpackSynchronisation($event)
		);
		self::assertSame(['Dossiq: a Mail getter threw'], $this->messages('debug'));
	}//end testASynchronisationIsUnpacked()

	/**
	 * Messages that are not a list are none.
	 *
	 * @return void
	 */
	public function testMessagesThatAreNotAListAreNone(): void {
		$event = new NewMessagesSynchronized(self::entity(3), self::entity(8, 'INBOX'), 'nothing');

		self::assertSame([], $this->gateway([])->unpackSynchronisation($event)['messages']);
	}//end testMessagesThatAreNotAListAreNone()

	/**
	 * An event whose account cannot be read is said out loud and unpacks to nothing.
	 *
	 * @return void
	 */
	public function testAnUnreadableEventIsLoggedAndUnpacksToNothing(): void {
		$event = new NewMessagesSynchronized(new RuntimeException('detached'), self::entity(8, 'INBOX'), []);

		self::assertNull($this->gateway([])->unpackSynchronisation($event));
		self::assertSame(['Dossiq: a Mail synchronisation event could not be read'], $this->messages('warning'));
		self::assertSame(['event' => NewMessagesSynchronized::class], $this->logged[array_key_last($this->logged)][2]);
	}//end testAnUnreadableEventIsLoggedAndUnpacksToNothing()
}//end class
