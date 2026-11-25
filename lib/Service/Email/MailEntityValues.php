<?php

/**
 * Dossiq MailEntityValues.
 *
 * Reads values off Nextcloud Mail's entities for NextcloudMailGateway: one
 * getter with its fallback, the rows of a synchronisation event and the rows
 * of an attachment list. It names no `OCA\Mail` symbol (design D-1); it only
 * ever sees plain objects and arrays the gateway hands it.
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Email;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Values off Mail's entities, built and read by NextcloudMailGateway only.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class MailEntityValues {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Call a getter on a Mail entity, or answer the fallback.
	 *
	 * `is_callable()`, not `method_exists()`: Mail's entities answer most
	 * getters (`getStatus`, `getSubject`) through `Entity::__call()`, which
	 * `method_exists()` cannot see, so it would answer the fallback for a
	 * getter that is really there. An unknown property throws from
	 * `__call()` and lands in the catch below.
	 *
	 * @param object                     $entity The entity.
	 * @param array{0: string, 1: mixed} $getter The getter, and what to answer when it is not there.
	 *
	 * @return mixed The value.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function call(object $entity, array $getter): mixed {
		[$method, $fallback] = $getter;
		if (is_callable([$entity, $method]) === false) {
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
	 * One Mail entity's numeric id.
	 *
	 * @param object $entity The entity.
	 *
	 * @return integer The id, or 0.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function identifierOf(object $entity): int {
		return (int)$this->call(entity: $entity, getter: ['getId', 0]);
	}//end identifierOf()

	/**
	 * One Mail entity's name.
	 *
	 * @param object $entity The entity.
	 *
	 * @return string The name, or ''.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function nameOf(object $entity): string {
		return (string)$this->call(entity: $entity, getter: ['getName', '']);
	}//end nameOf()

	/**
	 * A synchronisation event's parts as intake reads them, or null when the event cannot be read.
	 *
	 * The getters are reached through `call()`, by NAME. The event class ships
	 * in Nextcloud Mail, an optional runtime dependency this app carries no hard
	 * link to, so a named getter would read as a call on an unknown class; `call()`
	 * brings the guard and the throw handling with it.
	 *
	 * @param object $event The dispatched event, already known to be Mail's.
	 *
	 * @return array{accountId: int, mailbox: string, messages: array<int, array<string, mixed>>}|null
	 *         The unpacked event, or null when its account or mailbox cannot be read.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function synchronisation(object $event): ?array {
		$account = $this->call(entity: $event, getter: ['getAccount', null]);
		$mailbox = $this->call(entity: $event, getter: ['getMailbox', null]);
		$rows = $this->call(entity: $event, getter: ['getMessages', []]);

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
			'messages' => $this->eventMessages(rows: $rows),
		];
	}//end synchronisation()

	/**
	 * Turn the event's message entities into the rows intake reads.
	 *
	 * @param mixed $rows Whatever the event handed over.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function eventMessages(mixed $rows): array {
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
				'uid' => (int)$this->call(entity: $row, getter: ['getUid', 0]),
				'messageId' => (string)$this->call(entity: $row, getter: ['getMessageId', '']),
				'subject' => (string)$this->call(entity: $row, getter: ['getSubject', '']),
				'sentAt' => (string)$this->call(entity: $row, getter: ['getSentAt', '']),
			];
		}//end foreach

		return $messages;
	}//end eventMessages()

	/**
	 * The attachments Mail answered, as rows; anything that is not a list of arrays reads as none.
	 *
	 * @param mixed $found What Mail answered.
	 *
	 * @return array<int, array{name: string, mimeType: string, size: int}> The attachments.
	 *
	 * @spec openspec/specs/inbound-mail-filters/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function attachmentRows(mixed $found): array {
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
		}

		return $attachments;
	}//end attachmentRows()
}//end class
