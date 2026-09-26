<?php

/**
 * Read-only access to the Nextcloud Mail tables the case matcher scans.
 *
 * The matcher reads messages, not content it keeps: it needs a message's id,
 * IMAP uid, subject and the preview Mail caches beside it, and nothing else
 * leaves this class. The queries are the shape pipelinq's `EmailMatchService`
 * proved in production (`mail_messages` joined to `mail_mailboxes` on the
 * account, above a cursor, ascending, capped), split out so the service that
 * decides what to link can be tested without a query builder.
 *
 * 🔴 AN ACCOUNT ID IS NOT PROOF OF OWNERSHIP. A per-user setting names a Mail
 * account by its numeric id, and nothing in that id says whose it is. Every
 * caller that acts for a user asks {@see self::ownsAccount()} first, because
 * the email leaf's `linkEmail()` does not: it links whatever account it is
 * handed. pipelinq's matcher never asked, so a user who typed a colleague's
 * account id into its settings would have had the colleague's mailbox scanned
 * under their own name.
 *
 * Mail is an optional runtime dependency. Every read fails soft to an empty
 * answer when its tables are absent, and an empty answer is always the
 * refusing one: no account is owned, no message is listed.
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
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reads Mail accounts and messages for the case matcher.
 *
 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
 */
class MailMessageSource {

	/**
	 * Constructor.
	 *
	 * @param IDBConnection   $db     Database connection (Mail tables).
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly IDBConnection $db,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The Mail accounts a user owns.
	 *
	 * @param string $userId The user.
	 *
	 * @return array<int, array{id: int, name: string, email: string}> The accounts, by name.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function accountsOf(string $userId): array {
		if ($userId === '') {
			return [];
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'name', 'email')
				->from('mail_accounts')
				->where($qb->expr()->eq('user_id', $qb->createNamedParameter($userId)))
				->orderBy('name', 'ASC');

			$result = $qb->executeQuery();
			$accounts = [];
			while (($row = $result->fetch()) !== false) {
				$accounts[] = [
					'id' => (int)($row['id'] ?? 0),
					'name' => (string)($row['name'] ?? ''),
					'email' => (string)($row['email'] ?? ''),
				];
			}

			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: listing Mail accounts failed: ' . $e->getMessage());
			return [];
		}//end try

		return $accounts;
	}//end accountsOf()

	/**
	 * Every Mail account on the instance.
	 *
	 * Intake runs on a cron with no session, so the per-user listing above
	 * answers nothing there. An administrator picks the tenant's declared intake
	 * account from this list, which is why it is not scoped to the caller: the
	 * functional mailbox is normally owned by a service account nobody logs in
	 * as. The endpoint that serves it is admin-only for exactly that reason.
	 *
	 * @return array<int, array{id: int, name: string, email: string}> The accounts, by name.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function allAccounts(): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('id', 'name', 'email')
				->from('mail_accounts')
				->orderBy('name', 'ASC');

			$result = $qb->executeQuery();
			$accounts = [];
			while (($row = $result->fetch()) !== false) {
				$accounts[] = [
					'id' => (int) ($row['id'] ?? 0),
					'name' => (string) ($row['name'] ?? ''),
					'email' => (string) ($row['email'] ?? ''),
				];
			}

			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: listing every Mail account failed: ' . $e->getMessage());
			return [];
		}//end try

		return $accounts;
	}//end allAccounts()

	/**
	 * Whether a Mail account belongs to a user.
	 *
	 * A lookup that fails answers no. There is no reading of an unreadable
	 * table that makes an account somebody's.
	 *
	 * @param int    $accountId The Mail account id.
	 * @param string $userId    The user.
	 *
	 * @return bool True only when the account row names this user.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function ownsAccount(int $accountId, string $userId): bool {
		if ($accountId <= 0 || $userId === '') {
			return false;
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('user_id')
				->from('mail_accounts')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
				->setMaxResults(1);

			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: Mail account ownership lookup failed: ' . $e->getMessage());
			return false;
		}

		return (is_array($row) === true && (string)($row['user_id'] ?? '') === $userId);
	}//end ownsAccount()

	/**
	 * The highest message id an account currently holds.
	 *
	 * This is where a newly enabled cursor starts, so that switching matching
	 * on does not retro-link a whole mailbox history.
	 *
	 * @param int $accountId The Mail account id.
	 *
	 * @return int The highest message id, or 0 when the account holds none.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function maxMessageId(int $accountId): int {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select($qb->func()->max('m.id'))
				->from('mail_messages', 'm')
				->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('mb.id', 'm.mailbox_id'))
				->where($qb->expr()->eq('mb.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)));

			$result = $qb->executeQuery();
			$max = $result->fetchOne();
			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: reading the Mail high-water mark failed: ' . $e->getMessage());
			return 0;
		}

		return (int)$max;
	}//end maxMessageId()

	/**
	 * The messages an account received after a cursor, oldest first.
	 *
	 * Ordered by id ascending so the cursor advances monotonically, and capped
	 * so a large backlog cannot hold the database for one whole run.
	 *
	 * @param int $accountId The Mail account id.
	 * @param int $sinceId   The last processed message id.
	 * @param int $limit     The most messages to return.
	 *
	 * @return array<int, array{id: int, uid: string, subject: string, preview: string}> The messages.
	 *
	 * @spec openspec/changes/email-case-matching/specs/email-case-matching/spec.md
	 */
	public function listMessagesSince(int $accountId, int $sinceId, int $limit): array {
		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('m.id', 'm.uid', 'm.subject', 'm.preview_text')
				->from('mail_messages', 'm')
				->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('mb.id', 'm.mailbox_id'))
				->where($qb->expr()->eq('mb.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->gt('m.id', $qb->createNamedParameter($sinceId, IQueryBuilder::PARAM_INT)))
				->orderBy('m.id', 'ASC')
				->setMaxResults($limit);

			$result = $qb->executeQuery();
			$messages = [];
			while (($row = $result->fetch()) !== false) {
				$messages[] = [
					'id' => (int)($row['id'] ?? 0),
					'uid' => (string)($row['uid'] ?? ''),
					'subject' => (string)($row['subject'] ?? ''),
					'preview' => (string)($row['preview_text'] ?? ''),
				];
			}

			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: listing Mail messages failed: ' . $e->getMessage());
			return [];
		}//end try

		return $messages;
	}//end listMessagesSince()

	/**
	 * The user a Mail account belongs to.
	 *
	 * Intake runs on a cron with no session, and Nextcloud Mail scopes its
	 * trusted-sender list per user, so the only identity intake can name is the
	 * one that owns the mailbox it is reading.
	 *
	 * @param int $accountId The Mail account id.
	 *
	 * @return string The user id, or '' when the account cannot be read.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function ownerOf(int $accountId): string {
		if ($accountId <= 0) {
			return '';
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('user_id')
				->from('mail_accounts')
				->where($qb->expr()->eq('id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
				->setMaxResults(1);

			$result = $qb->executeQuery();
			$row = $result->fetch();
			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: reading the Mail account owner failed: ' . $e->getMessage());
			return '';
		}

		if (is_array($row) === false) {
			return '';
		}

		return (string) ($row['user_id'] ?? '');
	}//end ownerOf()

	/**
	 * The folders one account holds.
	 *
	 * @param int $accountId The Mail account id.
	 *
	 * @return array<int, string> The folder names.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function mailboxesOf(int $accountId): array {
		if ($accountId <= 0) {
			return [];
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('name')
				->from('mail_mailboxes')
				->where($qb->expr()->eq('account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
				->orderBy('name', 'ASC');

			$result = $qb->executeQuery();
			$names = [];
			while (($row = $result->fetch()) !== false) {
				$names[] = (string) ($row['name'] ?? '');
			}

			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: listing Mail folders failed: ' . $e->getMessage());
			return [];
		}//end try

		return array_values(array_filter($names));
	}//end mailboxesOf()

	/**
	 * The messages waiting in one folder of one account, oldest first.
	 *
	 * Only the fields intake cannot get from the raw source: its database id,
	 * the IMAP uid the raw source is fetched BY, and the folder name. Sender,
	 * subject and every threading header are read off the source itself, so this
	 * query never touches the recipients table and cannot drift with its shape.
	 *
	 * @param int    $accountId The Mail account id.
	 * @param string $mailbox   The folder name.
	 * @param int    $sinceId   The last processed message id.
	 * @param int    $limit     The most messages to return.
	 *
	 * @return array<int, array{id: int, uid: int, mailbox: string, messageId: string, subject: string, sentAt: string}>
	 *         The messages.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	public function listMailboxMessagesSince(int $accountId, string $mailbox, int $sinceId, int $limit): array {
		if ($accountId <= 0 || $mailbox === '' || $limit <= 0) {
			return [];
		}

		try {
			$qb = $this->db->getQueryBuilder();
			$qb->select('m.id', 'm.uid', 'm.message_id', 'm.subject', 'm.sent_at', 'mb.name')
				->from('mail_messages', 'm')
				->join('m', 'mail_mailboxes', 'mb', $qb->expr()->eq('mb.id', 'm.mailbox_id'))
				->where($qb->expr()->eq('mb.account_id', $qb->createNamedParameter($accountId, IQueryBuilder::PARAM_INT)))
				->andWhere($qb->expr()->eq('mb.name', $qb->createNamedParameter($mailbox)))
				->andWhere($qb->expr()->gt('m.id', $qb->createNamedParameter($sinceId, IQueryBuilder::PARAM_INT)))
				->orderBy('m.id', 'ASC')
				->setMaxResults($limit);

			$result = $qb->executeQuery();
			$messages = [];
			while (($row = $result->fetch()) !== false) {
				$messages[] = [
					'id' => (int) ($row['id'] ?? 0),
					'uid' => (int) ($row['uid'] ?? 0),
					'mailbox' => (string) ($row['name'] ?? $mailbox),
					'messageId' => (string) ($row['message_id'] ?? ''),
					'subject' => (string) ($row['subject'] ?? ''),
					'sentAt' => (string) ($row['sent_at'] ?? ''),
				];
			}

			$result->closeCursor();
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: listing the intake folder failed: ' . $e->getMessage());
			return [];
		}//end try

		return $messages;
	}//end listMailboxMessagesSince()
}//end class
