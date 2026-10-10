<?php

/**
 * The messages a resident reads on one of their cases.
 *
 * @category Portal
 * @package  OCA\Dossiq\Portal
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers portaliq's `caseMessages` provider method for one case (design D-4).
 *
 * ONLY THE RESIDENT'S OWN MESSAGES, AND THE RULE IS WRITTEN HERE ONCE. A case
 * can carry messages with more than one person: a co-applicant, a neighbour
 * who was heard. So a message is answered only when it is between the
 * handler and the resident the case is filed for (`case.portalSubject`): a
 * message they sent (`citizen_to_handler`, `senderRef`) or one sent to them
 * (`handler_to_citizen`, `recipientRef`). The resident's subject reference
 * is read from the CASE, never from the caller.
 *
 * THE READ RUNS AS THE SYSTEM, for this one case only. Portaliq calls the
 * method after its scoped read proved the case is the resident's, in a request
 * with no Nextcloud user, where OpenRegister's RBAC would answer nothing. The
 * case id is the only input and every row is checked against it, the same
 * posture as {@see PortalCaseDocuments::forCase()}.
 *
 * NO REFERENCE TRAVELS. The entries carry the inbox's own names (`body`,
 * `receivedAt`, `read`) and never the sender's or recipient's reference: a
 * handler's user id is nothing a resident needs, and their own subject
 * reference is a pseudonym the portal keeps server-side.
 *
 * @spec openspec/specs/portal-contribution/spec.md
 */
class PortalCaseMessages {
	use SearchesObjects;

	/**
	 * The most messages read per case.
	 *
	 * @var integer
	 */
	private const LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService OpenRegister access and the configured register and schemas.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The resident's messages on one case, newest first.
	 *
	 * @param string $caseId The case, already proven to be the resident's.
	 *
	 * @return array<int, array<string, mixed>> `{id, subject, body, receivedAt, direction, senderName, read, attachments}` per message.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function forCase(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if (trim($caseId) === '' || $objectService === null || $register === '') {
			return [];
		}

		try {
			return (array)$this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): array => $this->entries(objectService: $objectService, register: $register, caseId: $caseId)
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the portal messages of a case could not be read', ['reason' => $e->getMessage()]);
			return [];
		}
	}//end forCase()

	/**
	 * Whether a message is between the handler and this resident.
	 *
	 * @param array<string, mixed> $message  The portaalBericht.
	 * @param string               $resident The case's portal subject.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function isTheResidents(array $message, string $resident): bool {
		$direction = (string)($message['direction'] ?? '');
		if ($direction === 'citizen_to_handler') {
			return ($message['senderRef'] ?? null) === $resident;
		}

		if ($direction === 'handler_to_citizen') {
			return ($message['recipientRef'] ?? null) === $resident;
		}

		return false;
	}//end isTheResidents()

	/**
	 * The entries, read inside the system context.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The register.
	 * @param string $caseId        The case.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function entries(object $objectService, string $register, string $caseId): array {
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		$messageSchema = $this->settingsService->getConfigValue('portaal_bericht_schema');
		if ($caseSchema === '' || $messageSchema === '') {
			return [];
		}

		$case = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $caseSchema, id: $caseId);
		$resident = (string)($case['portalSubject'] ?? '');
		if ($resident === '') {
			return [];
		}

		$messages = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $messageSchema,
			filters: ['caseId' => $caseId, '_limit' => self::LIMIT]
		);

		$entries = [];
		foreach ($messages as $message) {
			if (($message['caseId'] ?? null) !== $caseId || $this->isTheResidents(message: $message, resident: $resident) === false) {
				continue;
			}

			$entries[] = $this->entry(message: $message);
		}

		usort($entries, static fn (array $a, array $b): int => strcmp((string)$b['receivedAt'], (string)$a['receivedAt']));

		return $entries;
	}//end entries()

	/**
	 * One message in the inbox's words.
	 *
	 * @param array<string, mixed> $message The portaalBericht.
	 *
	 * @return array<string, mixed>
	 */
	private function entry(array $message): array {
		$readAt = ($message['readByRecipientAt'] ?? null);

		return [
			'id' => (string)($message['id'] ?? ($message['@self']['id'] ?? '')),
			'subject' => (string)($message['subject'] ?? ''),
			'body' => (string)($message['content'] ?? ''),
			'receivedAt' => (string)($message['sentAt'] ?? ''),
			'direction' => (string)($message['direction'] ?? ''),
			'senderName' => (string)($message['senderName'] ?? ''),
			'read' => (is_string($readAt) === true && $readAt !== ''),
			'attachments' => array_values(array_filter((array)($message['attachments'] ?? []), 'is_string')),
		];
	}//end entry()
}//end class
