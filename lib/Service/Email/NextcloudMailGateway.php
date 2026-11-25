<?php

/**
 * Dossiq Nextcloud Mail Gateway
 *
 * 🔴 THIS IS THE ONLY FILE IN DOSSIQ THAT MAY NAME AN `OCA\Mail` SYMBOL.
 *
 * Nextcloud Mail publishes no `OCP` interface. `AccountService`, `MailManager`,
 * `DkimService`, `TrustedSenderService` and the synchronisation events are all
 * `OCA\Mail`, which means dossiq is coupled to another app's internals and a
 * Mail release can move any of them (design D-1). One adapter is what keeps the
 * blast radius at one file, and it is also what makes the coupling countable: a
 * grep for `OCA\Mail` outside this class is a finding, and
 * `tests/Unit/Architecture/MailCouplingTest` fails on one.
 *
 * Every symbol is reached through the DI container BY NAME rather than
 * type-hinted, for the same reason `SettingsService::getObjectService()` reaches
 * for OpenRegister that way: Mail is an optional runtime dependency, and a
 * constructor that type-hints an absent class cannot even be built. Every call
 * is duck-typed with `method_exists()` and wrapped, so a Mail release that
 * renames a method degrades this app to "intake unavailable" rather than
 * throwing on a cron run nobody is watching.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO. It opens no IMAP connection, holds
 * no password and runs no OAuth 2.0 flow. Nextcloud Mail owns the account, the
 * credential and the transport; dossiq owns the pipeline, the verdicts, the
 * policy and the log (decision D12, answered for Nextcloud Mail).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Email
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Everything dossiq asks Nextcloud Mail for, in one place.
 *
 * Every `OCA\Mail` name is written here and nowhere else (design D-1). The
 * work is done by five collaborators the gateway builds and hands Mail's
 * resolved objects to, none of which names a Mail symbol: `MailServices`
 * (resolve a service or an account by the name given here), `MailMessageCalls`
 * (source, message-id lookup, move, attachments), `MailSenderChecks` (DKIM and
 * trusted senders), `MailOutboxDelivery` (save and send through the outbox)
 * and `MailEntityValues` (getters off Mail's entities and events). So a grep
 * for `OCA\Mail` still measures the coupling, and the class no longer needs a
 * class-level complexity suppression (method-decomposition slice 8n).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/inbound-mail-filters/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 *
 * @SuppressWarnings(PHPMD.TooManyPublicMethods) The eleventh is sendMessage(),
 *  the outbound half of the same Mail coupling (inbound-mail-filters 8.1). Design D-1
 *  keeps every `OCA\Mail` symbol in this one class, so a second gateway class for
 *  sending would split the coupling this suppression exists to keep countable.
 */
class NextcloudMailGateway implements MailGatewayInterface {

	/**
	 * The app id of Nextcloud Mail.
	 *
	 * 🔴 NEVER point this at another name. `isInstalled()` is a duck-typed
	 * runtime lookup: an id nothing answers to makes this gateway silently
	 * report "unavailable" forever rather than erroring.
	 */
	public const MAIL_APP_ID = 'mail';

	/**
	 * The event Nextcloud Mail dispatches when a folder gains messages.
	 *
	 * Published here as a constant so `Application` can register a listener for
	 * it without naming an `OCA\Mail` class itself.
	 */
	public const NEW_MESSAGES_EVENT = 'OCA\\Mail\\Events\\NewMessagesSynchronized';

	/**
	 * Mail's account service.
	 */
	private const ACCOUNT_SERVICE = 'OCA\\Mail\\Service\\AccountService';

	/**
	 * Mail's mailbox and message manager.
	 */
	private const MAIL_MANAGER = 'OCA\\Mail\\Service\\MailManager';

	/**
	 * Mail's IMAP client factory: Mail 5's getSource() reads through a client the caller opens.
	 */
	private const IMAP_CLIENT_FACTORY = 'OCA\\Mail\\IMAP\\IMAPClientFactory';

	/**
	 * Mail's DKIM verifier.
	 */
	private const DKIM_SERVICE = 'OCA\\Mail\\Service\\DkimService';

	/**
	 * Mail's administered allow list.
	 */
	private const TRUSTED_SENDER_SERVICE = 'OCA\\Mail\\Service\\TrustedSenderService';

	/**
	 * Mail's outbox: saves a message for an account and sends it through the account's own transport.
	 */
	private const OUTBOX_SERVICE = 'OCA\\Mail\\Service\\OutboxService';

	/**
	 * Mail's outbox row. Built with `new`, never through the container, which
	 * would hand every caller the same shared instance.
	 */
	private const LOCAL_MESSAGE = 'OCA\\Mail\\Db\\LocalMessage';

	/**
	 * `LocalMessage::TYPE_OUTGOING`.
	 */
	private const TYPE_OUTGOING = 0;

	/**
	 * Mail's services and accounts, resolved by the names above.
	 *
	 * @var MailServices
	 */
	private readonly MailServices $services;

	/**
	 * Getters off Mail's entities and events.
	 *
	 * @var MailEntityValues
	 */
	private readonly MailEntityValues $values;

	/**
	 * The calls on Mail's message manager.
	 *
	 * @var MailMessageCalls
	 */
	private readonly MailMessageCalls $calls;

	/**
	 * Mail's DKIM verdict and trusted-sender answers.
	 *
	 * @var MailSenderChecks
	 */
	private readonly MailSenderChecks $senders;

	/**
	 * One message through Mail's outbox.
	 *
	 * @var MailOutboxDelivery
	 */
	private readonly MailOutboxDelivery $delivery;

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager The app manager, to see whether Mail is there.
	 * @param ContainerInterface $container  The DI container, to resolve Mail's services by name.
	 * @param MailMessageSource  $mailTables Read-only access to the Mail tables.
	 * @param LoggerInterface    $logger     Logger.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		IAppManager $appManager,
		ContainerInterface $container,
		private readonly MailMessageSource $mailTables,
		LoggerInterface $logger,
	) {
		$this->services = new MailServices(
			appManager: $appManager,
			container: $container,
			logger: $logger,
			mailNames: [self::MAIL_APP_ID, self::ACCOUNT_SERVICE]
		);
		$this->values   = new MailEntityValues(logger: $logger);
		$this->calls    = new MailMessageCalls(values: $this->values, logger: $logger);
		$this->senders  = new MailSenderChecks(mailTables: $mailTables, logger: $logger);
		$this->delivery = new MailOutboxDelivery(
			values: $this->values,
			localMessage: fn (int $accountId, array $message): object => $this->localMessage(
				accountId: $accountId,
				message: $message
			),
			logger: $logger
		);
	}//end __construct()

	/**
	 * Whether Nextcloud Mail is installed and answering.
	 *
	 * @return boolean True when intake can read messages.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function isAvailable(): bool {
		return $this->services->isAvailable();
	}//end isAvailable()

	/**
	 * The mail accounts an administrator can pick between.
	 *
	 * Read from the Mail tables rather than through `AccountService`, which
	 * scopes to one user and therefore answers nothing in the session-less
	 * contexts (cron, occ, repair) this gateway mostly runs in.
	 *
	 * @return array<int, array{id: int, name: string, email: string}> The accounts.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 */
	public function accounts(): array {
		if ($this->isAvailable() === false) {
			return [];
		}

		return $this->mailTables->allAccounts();
	}//end accounts()

	/**
	 * The folders of one account.
	 *
	 * @param integer $accountId The account.
	 *
	 * @return array<int, string> The folder names.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 */
	public function mailboxes(int $accountId): array {
		if ($this->isAvailable() === false) {
			return [];
		}

		return $this->mailTables->mailboxesOf(accountId: $accountId);
	}//end mailboxes()

	/**
	 * The messages waiting in one folder, oldest first.
	 *
	 * @param integer $accountId The account.
	 * @param string  $mailbox   The folder.
	 * @param integer $limit     How many at most.
	 *
	 * @return array<int, array<string, mixed>> Normalised message rows.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 */
	public function messages(int $accountId, string $mailbox, int $limit): array {
		if ($this->isAvailable() === false) {
			return [];
		}

		return $this->mailTables->listMailboxMessagesSince(
			accountId: $accountId,
			mailbox: $mailbox,
			sinceId: 0,
			limit: $limit
		);
	}//end messages()

	/**
	 * The raw source of a message, headers included.
	 *
	 * @param integer $accountId The account.
	 * @param string  $mailbox   The folder.
	 * @param integer $uid       The message uid.
	 *
	 * @return string The source, or '' when it cannot be read.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function source(int $accountId, string $mailbox, int $uid): string {
		$call = $this->services->accountWith(accountId: $accountId, name: self::MAIL_MANAGER, method: 'getSource');
		if ($call === null) {
			return '';
		}

		return $this->calls->source(
			call: $call,
			clientFactory: fn (): ?object => $this->services->service(name: self::IMAP_CLIENT_FACTORY),
			message: [$accountId, $mailbox, $uid]
		);
	}//end source()

	/**
	 * Whether this account holds a message with that id.
	 *
	 * 🔴 AN EMPTY ANSWER IS "NO", AND THAT IS THE SAFE DIRECTION. This is the
	 * check that stops a forged `In-Reply-To` reaching somebody else's case, so
	 * a lookup that could not run must not read as a match.
	 *
	 * @param integer $accountId The account.
	 * @param string  $messageId The message id, angle brackets optional.
	 *
	 * @return boolean True only when the account really holds it.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function holdsMessageId(int $accountId, string $messageId): bool {
		$bare = trim($messageId, " \t<>");
		if ($bare === '') {
			return false;
		}

		$call = $this->services->accountWith(accountId: $accountId, name: self::MAIL_MANAGER, method: 'getByMessageId');
		if ($call === null) {
			return false;
		}

		return $this->calls->holds(call: $call, accountId: $accountId, messageId: $bare);
	}//end holdsMessageId()

	/**
	 * File a message in another folder of the same account.
	 *
	 * @param integer $accountId The account.
	 * @param string  $mailbox   The folder it is in.
	 * @param integer $uid       The message uid.
	 * @param string  $target    The folder it goes to.
	 *
	 * @return boolean True when the mail server confirmed the move.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function moveMessage(int $accountId, string $mailbox, int $uid, string $target): bool {
		if ($mailbox === '' || $target === '' || $mailbox === $target) {
			return false;
		}

		$call = $this->services->accountWith(accountId: $accountId, name: self::MAIL_MANAGER, method: 'moveMessage');
		if ($call === null) {
			return false;
		}

		return $this->calls->move(call: $call, move: [$accountId, $mailbox, $uid, $target]);
	}//end moveMessage()

	/**
	 * The DKIM verdict Mail reaches for a raw message.
	 *
	 * Mail's verifier answers a boolean, which cannot distinguish "no signature"
	 * from "a signature that did not verify". So an unsigned message is reported
	 * as `none` from the absence of a `DKIM-Signature` header, and only a
	 * message that carries one can be `pass` or `fail`. When Mail is absent the
	 * answer is `unavailable`, never `pass`.
	 *
	 * @param string $source The raw message source.
	 *
	 * @return string One of the {@see AuthenticationResult} values.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function dkimResult(string $source): string {
		return $this->senders->dkimResult(
			source: $source,
			validator: fn (): ?object => $this->services->serviceWith(name: self::DKIM_SERVICE, method: 'validateRaw')
		);
	}//end dkimResult()

	/**
	 * Whether Mail's trusted-sender list carries this address.
	 *
	 * The list is per user and intake has no session, so the identity asked
	 * about is the one that owns the mailbox being read.
	 *
	 * @param string $email The sender address.
	 *
	 * @return boolean True when the address is trusted.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function isTrustedSender(string $email): bool {
		return $this->senders->isTrustedSender(
			email: $email,
			service: fn (): ?object => $this->services->serviceWith(name: self::TRUSTED_SENDER_SERVICE, method: 'isTrusted')
		);
	}//end isTrustedSender()

	/**
	 * The attachments of a message.
	 *
	 * @param integer $accountId The account.
	 * @param string  $mailbox   The folder.
	 * @param integer $uid       The message uid.
	 *
	 * @return array<int, array{name: string, mimeType: string, size: int}> The attachments.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function attachments(int $accountId, string $mailbox, int $uid): array {
		$call = $this->services->accountWith(accountId: $accountId, name: self::MAIL_MANAGER, method: 'getMailAttachments');
		if ($call === null) {
			return [];
		}

		return $this->calls->attachments(call: $call, message: [$accountId, $mailbox, $uid]);
	}//end attachments()

	/**
	 * Unpack a Mail synchronisation event into normalised message rows.
	 *
	 * @param object $event The dispatched event.
	 *
	 * @return array{accountId: int, mailbox: string, messages: array<int, array<string, mixed>>}|null
	 *         The unpacked event, or null when it is not one intake reads.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function unpackSynchronisation(object $event): ?array {
		if (is_a($event, self::NEW_MESSAGES_EVENT) === false) {
			return null;
		}

		return $this->values->synchronisation(event: $event);
	}//end unpackSynchronisation()

	/**
	 * Send one message through a Mail account, on that account's own authentication.
	 *
	 * Saves the message in Mail's outbox for the account and asks Mail to send
	 * it. Mail opens the connection with the credential it holds, files the
	 * copy in the account's sent folder, and keeps a message it could not send
	 * in its outbox, where its own background job retries it. dossiq never
	 * touches SMTP, a password or an OAuth token here.
	 *
	 * Attachments are handed over as `cloud` attachments: Mail reads each path
	 * from the sending user's own files and checks the download permission
	 * itself, which is the same boundary dossiq's attachment resolver drew.
	 *
	 * @param integer              $accountId The account the message leaves from.
	 * @param array<string, mixed> $message   to, subject, html, plain, attachments.
	 *
	 * @return array{state: string, outboxId: int|null} What became of it.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md#requirement-outbound-mail-leaves-through-the-same-account-with-no-dossiq-credential-req-imf-11
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function sendMessage(int $accountId, array $message): array {
		$account = $this->services->account(accountId: $accountId);
		$outbox  = $this->outbox();
		if ($account === null || $outbox === null) {
			return ['state' => OutboundState::UNAVAILABLE, 'outboxId' => null];
		}

		return $this->delivery->deliver(
			through: [$accountId, $account, $outbox],
			message: $message
		);
	}//end sendMessage()

	/**
	 * Mail's outbox service, or null when Mail is absent or moved it.
	 *
	 * Its row class must exist and it must offer both verbs.
	 *
	 * @return object|null The outbox.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function outbox(): ?object {
		$outbox = $this->services->serviceWith(name: self::OUTBOX_SERVICE, method: 'saveMessage');
		if ($outbox === null || class_exists(self::LOCAL_MESSAGE) === false || method_exists($outbox, 'sendMessage') === false) {
			return null;
		}

		return $outbox;
	}//end outbox()

	/**
	 * Build Mail's outbox row for one message.
	 *
	 * @param integer              $accountId The account.
	 * @param array<string, mixed> $message   subject, html, plain.
	 *
	 * @return object The unsaved LocalMessage.
	 *
	 * @throws RuntimeException When Mail moved its outbox row class; the delivery treats it as unavailable.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function localMessage(int $accountId, array $message): object {
		$class = self::LOCAL_MESSAGE;
		if (class_exists($class) === false) {
			throw new RuntimeException('Nextcloud Mail has no outbox row class');
		}

		$local = new $class();
		$local->setType(self::TYPE_OUTGOING);
		$local->setAccountId($accountId);
		$local->setSubject((string)($message['subject'] ?? ''));
		$local->setBodyHtml((string)($message['html'] ?? ''));
		$local->setBodyPlain((string)($message['plain'] ?? ''));
		$local->setHtml(true);
		// Due now, so Mail's own outbox job picks it up again if this send fails.
		$local->setSendAt(time());

		return $local;
	}//end localMessage()
}//end class
