<?php

/**
 * Dossiq portal inbox channel.
 *
 * A requester notice as a message in the case's portal inbox, from the
 * organisation to the case's portal subject, saved as the system.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Notification
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-requester-notice-is-stored-on-the-case-with-its-result-req-wrn-006
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Notification;

use DateTimeImmutable;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Delivers one notice to the portal inbox, or says why it could not.
 *
 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-requester-notice-is-stored-on-the-case-with-its-result-req-wrn-006
 */
final class PortalInboxChannel {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param IAppManager|null $appManager Whether portaliq is installed.
	 * @param LoggerInterface  $logger     Logger.
	 */
	public function __construct(
		private readonly ?IAppManager $appManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The portal inbox: a `portaalBericht` from the organisation to the case's portal subject.
	 *
	 * @param array<string, mixed> $case   The case row.
	 * @param array<string, mixed> $notice The notice.
	 * @param array<int, mixed>|null $store  The object service, register and portal message schema, or null when not configured.
	 *
	 * @return array<string, mixed>|null Null when the case has no portal subject or portaliq is absent.
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-requester-notice-is-stored-on-the-case-with-its-result-req-wrn-006
	 */
	public function deliver(array $case, array $notice, ?array $store): ?array {
		$subject = trim((string)($case['portalSubject'] ?? ''));
		if ($subject === '' || $this->appManager?->isInstalled(RequesterNoticeSender::PORTAL_APP) !== true) {
			return null;
		}

		if ($store === null) {
			return $this->refused(code: 'portal-inbox-unavailable', reason: 'The portal inbox is not configured, so the notice could not be put there.');
		}

		$message = $this->portalMessage(case: $case, notice: $notice);
		try {
			$saved = $this->savePortalMessage(store: $store, message: $message);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq requester notice: the portal inbox refused it', ['exception' => $e]);
			return $this->refused(code: 'portal-inbox-failed', reason: 'The portal inbox did not accept the notice.');
		}

		return $this->portalAnswer(saved: $saved, sentAt: $message['sentAt']);
	}//end deliver()

	/**
	 * The portal message for the requester, sent now.
	 *
	 * @param array<string, mixed>  $case   The case row; its portal subject is the recipient.
	 * @param array<string, string> $notice The notice.
	 *
	 * @return array<string, string>
	 */
	private function portalMessage(array $case, array $notice): array {
		return [
			'caseId' => (string)($case['id'] ?? ''),
			'caseReference' => (string)($case['identifier'] ?? ''),
			'recipientRef' => trim((string)($case['portalSubject'] ?? '')),
			'senderRef' => 'dossiq',
			'senderType' => 'medewerker',
			'senderName' => 'Gemeente',
			'subject' => $notice['subject'],
			'content' => $notice['body'],
			'direction' => 'handler_to_citizen',
			'sentAt' => (new DateTimeImmutable())->format('c'),
		];
	}//end portalMessage()

	/**
	 * Save the message in the requester's portal inbox.
	 *
	 * @param array{0: object, 1: string, 2: string} $store   The object service, the register and the schema.
	 * @param array<string, string>                  $message The portal message.
	 *
	 * @return array<string, mixed>|null The saved message.
	 */
	private function savePortalMessage(array $store, array $message): ?array {
		[$objectService, $register, $schema] = $store;

		// As the system: a background job has no user, and the requester's
		// inbox is not a register the acting handler holds a grant on.
		return $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): ?array => $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $message
			)
		);
	}//end savePortalMessage()

	/**
	 * Sent with the saved message's id, or refused when the inbox gave none.
	 *
	 * @param mixed  $saved  What the portal inbox saved.
	 * @param string $sentAt When the message was sent.
	 *
	 * @return array<string, mixed>
	 */
	private function portalAnswer(mixed $saved, string $sentAt): array {
		$row = [];
		if (is_array($saved) === true) {
			$row = $saved;
		}

		$messageId = $this->idOf(row: $row);
		if ($messageId === '') {
			return $this->refused(code: 'portal-inbox-failed', reason: 'The portal inbox did not accept the notice.');
		}

		return ['sent' => true, 'messageId' => $messageId, 'sentAt' => $sentAt];
	}//end portalAnswer()

	/**
	 * The id of a saved row.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The id, or ''.
	 */
	private function idOf(array $row): string {
		$self = (array)($row['@self'] ?? []);
		return trim((string)($row['id'] ?? ($row['uuid'] ?? ($self['id'] ?? ''))));
	}//end idOf()
	/**
	 * The channel's refusal.
	 *
	 * @param string $code   The reason code.
	 * @param string $reason The sentence.
	 *
	 * @return array{sent: false, reasonCode: string, reason: string}
	 */
	private function refused(string $code, string $reason): array {
		return ['sent' => false, 'reasonCode' => $code, 'reason' => $reason];
	}//end refused()
}//end class
