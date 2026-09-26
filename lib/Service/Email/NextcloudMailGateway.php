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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Everything dossiq asks Nextcloud Mail for, in one place.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 *
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity) — the complexity is the price of
 *  design D-1: ONE class names every `OCA\Mail` symbol, guards each one and answers
 *  a documented empty value when Mail is absent. Splitting it would spread that
 *  coupling over several files and make a grep for `OCA\Mail` stop being the
 *  measurement it is.
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
	 * Mail's DKIM verifier.
	 */
	private const DKIM_SERVICE = 'OCA\\Mail\\Service\\DkimService';

	/**
	 * Mail's administered allow list.
	 */
	private const TRUSTED_SENDER_SERVICE = 'OCA\\Mail\\Service\\TrustedSenderService';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager The app manager, to see whether Mail is there.
	 * @param ContainerInterface $container  The DI container, to resolve Mail's services by name.
	 * @param MailMessageSource  $mailTables Read-only access to the Mail tables.
	 * @param LoggerInterface    $logger     Logger.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly MailMessageSource $mailTables,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether Nextcloud Mail is installed and answering.
	 *
	 * @return boolean True when intake can read messages.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function isAvailable(): bool {
		try {
			return $this->appManager->isInstalled(self::MAIL_APP_ID);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: could not ask whether the Mail app is installed',
				['error' => $e->getMessage()]
			);
			return false;
		}
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
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
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
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
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
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
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
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function source(int $accountId, string $mailbox, int $uid): string {
		$account = $this->account(accountId: $accountId);
		$manager = $this->service(name: self::MAIL_MANAGER);
		if ($account === null || $manager === null || method_exists($manager, 'getSource') === false) {
			return '';
		}

		try {
			$source = $manager->getSource($account, $mailbox, $uid);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: reading a message source failed',
				['account' => $accountId, 'uid' => $uid, 'error' => $e->getMessage()]
			);
			return '';
		}

		if (is_string($source) === false) {
			return '';
		}

		return $source;
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
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function holdsMessageId(int $accountId, string $messageId): bool {
		$bare = trim($messageId, " \t<>");
		if ($bare === '') {
			return false;
		}

		$account = $this->account(accountId: $accountId);
		$manager = $this->service(name: self::MAIL_MANAGER);
		if ($account === null || $manager === null || method_exists($manager, 'getByMessageId') === false) {
			return false;
		}

		try {
			$found = $manager->getByMessageId($account, $bare);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the threading lookup failed, so the claim stays unproven',
				['account' => $accountId, 'error' => $e->getMessage()]
			);
			return false;
		}

		return (is_array($found) === true && $found !== []);
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
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function moveMessage(int $accountId, string $mailbox, int $uid, string $target): bool {
		if ($mailbox === '' || $target === '' || $mailbox === $target) {
			return false;
		}

		$account = $this->account(accountId: $accountId);
		$manager = $this->service(name: self::MAIL_MANAGER);
		if ($account === null || $manager === null || method_exists($manager, 'moveMessage') === false) {
			return false;
		}

		try {
			$manager->moveMessage($account, $mailbox, $uid, $account, $target);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: moving a message between folders failed',
				['account' => $accountId, 'uid' => $uid, 'target' => $target, 'error' => $e->getMessage()]
			);
			return false;
		}

		return true;
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
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function dkimResult(string $source): string {
		if ($source === '') {
			return AuthenticationResult::UNAVAILABLE;
		}

		if (stripos($source, 'DKIM-Signature:') === false) {
			return AuthenticationResult::NONE;
		}

		$validator = $this->service(name: self::DKIM_SERVICE);
		if ($validator === null || method_exists($validator, 'validateRaw') === false) {
			return AuthenticationResult::UNAVAILABLE;
		}

		try {
			$valid = $validator->validateRaw($source);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: the DKIM check could not run',
				['error' => $e->getMessage()]
			);
			return AuthenticationResult::UNAVAILABLE;
		}

		if (is_bool($valid) === false) {
			return AuthenticationResult::UNAVAILABLE;
		}

		if ($valid === true) {
			return AuthenticationResult::PASS;
		}

		return AuthenticationResult::FAIL;
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
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function isTrustedSender(string $email): bool {
		$address = trim(strtolower($email));
		if ($address === '') {
			return false;
		}

		$service = $this->service(name: self::TRUSTED_SENDER_SERVICE);
		if ($service === null || method_exists($service, 'isTrusted') === false) {
			return false;
		}

		foreach ($this->mailTables->allAccounts() as $account) {
			$owner = $this->mailTables->ownerOf(accountId: (int)$account['id']);
			if ($owner === '') {
				continue;
			}

			try {
				$trusted = $service->isTrusted($owner, $address);
			} catch (Throwable $e) {
				$this->logger->debug(
					'Dossiq: the trusted-sender lookup failed',
					['error' => $e->getMessage()]
				);
				continue;
			}

			if ($trusted === true) {
				return true;
			}
		}//end foreach

		return false;
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
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function attachments(int $accountId, string $mailbox, int $uid): array {
		$account = $this->account(accountId: $accountId);
		$manager = $this->service(name: self::MAIL_MANAGER);
		if ($account === null || $manager === null || method_exists($manager, 'getMailAttachments') === false) {
			return [];
		}

		try {
			$found = $manager->getMailAttachments($account, $mailbox, $uid);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: reading attachments failed',
				['account' => $accountId, 'uid' => $uid, 'error' => $e->getMessage()]
			);
			return [];
		}

		if (is_array($found) === false) {
			return [];
		}

		$attachments = [];
		foreach ($found as $attachment) {
			if (is_array($attachment) === false) {
				continue;
			}

			$attachments[] = [
				'name' => (string)($attachment['fileName'] ?? ($attachment['name'] ?? '')),
				'mimeType' => (string)($attachment['mime'] ?? ($attachment['mimeType'] ?? '')),
				'size' => (int)($attachment['size'] ?? 0),
			];
		}//end foreach

		return $attachments;
	}//end attachments()

	/**
	 * Unpack a Mail synchronisation event into normalised message rows.
	 *
	 * @param object $event The dispatched event.
	 *
	 * @return array{accountId: int, mailbox: string, messages: array<int, array<string, mixed>>}|null
	 *         The unpacked event, or null when it is not one intake reads.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function unpackSynchronisation(object $event): ?array {
		if (is_a($event, self::NEW_MESSAGES_EVENT) === false) {
			return null;
		}

		// The getters are reached through `call()`, by NAME, and not written
		// as `$event->getAccount()`.
		//
		// `is_a()` with the constant class-string narrows `$event` to
		// OCA\Mail\Events\NewMessagesSynchronized. That class ships in
		// Nextcloud Mail, which is an optional runtime dependency this app
		// deliberately carries no hard link to, so it is absent from the
		// analysis tree and every named getter reads as a call on an unknown
		// class. `call()` takes a plain `object` plus a method name, which is
		// the same indirection the account, mailbox and message entities
		// already go through further down, and it brings the `method_exists`
		// guard and the throw handling with it.
		$account = $this->call(entity: $event, method: 'getAccount', fallback: null);
		$mailbox = $this->call(entity: $event, method: 'getMailbox', fallback: null);
		$rows = $this->call(entity: $event, method: 'getMessages', fallback: []);

		if (is_object($account) === false || is_object($mailbox) === false) {
			// A Mail release that moved the event's shape arrives here, and so
			// does a getter that threw. Both mean the run ends with no
			// messages, which is indistinguishable from a quiet mailbox unless
			// it is said out loud.
			$this->logger->warning(
				'Dossiq: a Mail synchronisation event could not be read',
				['event' => get_class($event)]
			);
			return null;
		}

		return [
			'accountId' => $this->identifierOf(entity: $account),
			'mailbox' => $this->nameOf(entity: $mailbox),
			'messages' => $this->normaliseEventMessages(rows: $rows),
		];
	}//end unpackSynchronisation()

	/**
	 * Turn the event's message entities into the rows intake reads.
	 *
	 * @param mixed $rows Whatever the event handed over.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function normaliseEventMessages(mixed $rows): array {
		if (is_array($rows) === false) {
			return [];
		}

		$messages = [];
		foreach ($rows as $row) {
			if (is_object($row) === false) {
				continue;
			}

			$messages[] = [
				'id' => $this->identifierOf(entity: $row),
				'uid' => (int)$this->call(entity: $row, method: 'getUid', fallback: 0),
				'messageId' => (string)$this->call(entity: $row, method: 'getMessageId', fallback: ''),
				'subject' => (string)$this->call(entity: $row, method: 'getSubject', fallback: ''),
				'sentAt' => (string)$this->call(entity: $row, method: 'getSentAt', fallback: ''),
			];
		}//end foreach

		return $messages;
	}//end normaliseEventMessages()

	/**
	 * One Mail entity's numeric id.
	 *
	 * @param object $entity The entity.
	 *
	 * @return integer The id, or 0.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function identifierOf(object $entity): int {
		return (int)$this->call(entity: $entity, method: 'getId', fallback: 0);
	}//end identifierOf()

	/**
	 * One Mail entity's name.
	 *
	 * @param object $entity The entity.
	 *
	 * @return string The name, or ''.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function nameOf(object $entity): string {
		return (string)$this->call(entity: $entity, method: 'getName', fallback: '');
	}//end nameOf()

	/**
	 * Call a getter on a Mail entity, or answer the fallback.
	 *
	 * @param object $entity   The entity.
	 * @param string $method   The getter.
	 * @param mixed  $fallback What to answer when the getter is not there.
	 *
	 * @return mixed The value.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function call(object $entity, string $method, mixed $fallback): mixed {
		if (method_exists($entity, $method) === false) {
			return $fallback;
		}

		try {
			$value = $entity->{$method}();
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: a Mail getter threw',
				['method' => $method, 'error' => $e->getMessage()]
			);
			return $fallback;
		}

		if ($value === null) {
			return $fallback;
		}

		return $value;
	}//end call()

	/**
	 * Resolve one of Mail's services by name.
	 *
	 * @param string $name The fully qualified class name.
	 *
	 * @return object|null The service, or null when Mail is absent or moved it.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function service(string $name): ?object {
		if ($this->isAvailable() === false) {
			return null;
		}

		try {
			$service = $this->container->get($name);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: a Nextcloud Mail service could not be resolved, so intake is unavailable',
				['service' => $name, 'error' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($service) === false) {
			return null;
		}

		return $service;
	}//end service()

	/**
	 * Resolve one Mail account entity.
	 *
	 * @param integer $accountId The account.
	 *
	 * @return object|null The account, or null when it cannot be resolved.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function account(int $accountId): ?object {
		if ($accountId <= 0) {
			return null;
		}

		$service = $this->service(name: self::ACCOUNT_SERVICE);
		if ($service === null || method_exists($service, 'findById') === false) {
			return null;
		}

		try {
			$account = $service->findById($accountId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the configured intake account could not be read',
				['account' => $accountId, 'error' => $e->getMessage()]
			);
			return null;
		}

		if (is_object($account) === false) {
			return null;
		}

		return $account;
	}//end account()
}//end class
