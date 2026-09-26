<?php

/**
 * Dossiq Berichtenbox Service.
 *
 * Sends citizen-facing messages through a pluggable Mijn Overheid
 * Berichtenbox adapter and records them in OpenRegister.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/berichtenbox-integration/spec.md
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTime;
use OCA\Dossiq\Service\Berichtenbox\BerichtenboxJournal;
use OCA\Dossiq\Service\BerichtenboxAdapter\BerichtenboxAdapterInterface;
use OCA\Dossiq\Service\Support\OwningCaseResolver;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Service for sending messages to Mijn Overheid Berichtenbox.
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */
class BerichtenboxService {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service.
	 * @param IAppManager $appManager The Nextcloud app manager.
	 * @param ContainerInterface $container The DI container.
	 * @param LoggerInterface $logger The logger.
	 * @param OwningCaseResolver $owningCase Resolves a message's owning case.
	 * @param BerichtenboxAdapterInterface $adapter The transport this instance has.
	 * @param BerichtenboxJournal $journal What a send leaves behind for a reader.
	 */
	public function __construct(
		private SettingsService $settingsService,
		private IAppManager $appManager,
		private ContainerInterface $container,
		private LoggerInterface $logger,
		private readonly OwningCaseResolver $owningCase,
		private readonly BerichtenboxAdapterInterface $adapter,
		private readonly BerichtenboxJournal $journal,
	) {
	}//end __construct()

	/**
	 * Send a message to the Berichtenbox.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $bsn Citizen BSN.
	 * @param string $subject Message subject.
	 * @param string $body Plain text message body.
	 * @param string $typeCode Bericht type code.
	 * @param string|null $attachmentFileId Optional Nextcloud file ID of the attachment.
	 *
	 * @return array<string, mixed> The stored message record or an error payload.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function sendMessage(
		string $caseId,
		string $bsn,
		string $subject,
		string $body,
		string $typeCode,
		?string $attachmentFileId = null,
	): array {
		// Validate inputs.
		$errors = $this->validateMessage(bsn: $bsn, subject: $subject, body: $body);
		if (empty($errors) === false) {
			return ['error' => implode('; ', $errors), 'errors' => $errors];
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['error' => 'OpenRegister is not available'];
		}

		// Get attachment content if provided.
		$attachmentContent = null;
		if ($attachmentFileId !== null) {
			// Attachment validation would check file size here.
			$attachmentContent = '';
			// Placeholder -- actual file reading via IRootFolder.
		}

		// Send via whichever adapter this instance has. The default is
		// IntegriqAdapter, which dispatches integriq's typed send command and
		// refuses rather than simulating; the mock is still selectable by
		// naming it in `berichtenbox_adapter`, and it is no longer what an
		// instance that configured nothing silently gets.
		$result = $this->adapter->sendMessage($bsn, $subject, $body, $typeCode, $attachmentContent);

		// 🔴 A REFUSAL IS NOT A DELIVERY, AND THE DIFFERENCE IS THE WHOLE
		// CHANGE. A refused send gets a message record too, because a handler
		// who pressed Send must be able to see what happened to the letter
		// they wrote, but it carries status `refused`, no external message id
		// and a reason, and its timeline entry is INTERNAL rather than public:
		// telling a citizen on the portal that we tried to write to them and
		// failed is not what the portal is for.
		$refused = (($result['refused'] ?? false) === true);

		// Store message record.
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('berichtenbox_message_schema');

		$messageData = $this->journal->messageRecord(
			caseId: $caseId,
			bsn: $bsn,
			subject: $subject,
			body: $body,
			typeCode: $typeCode,
			attachmentFileId: $attachmentFileId,
			result: $result,
		);

		$saved = $objectService->saveObject(
			object: $messageData,
			register: (int)$register,
			schema: (int)$schema,
		);

		$this->journal->recordSend(caseId: $caseId, subject: $subject, result: $result, messageData: $messageData);

		$this->logSendOutcome(caseId: $caseId, result: $result);

		$stored = $saved->jsonSerialize();
		if ($refused === true) {
			// The caller is a controller answering a handler who just pressed
			// Send. It has to be able to tell a refusal from a send WITHOUT
			// reading a status string it would have to know the vocabulary of.
			$stored['refused'] = true;
			$stored['error'] = (string)($result['error'] ?? '');
		}

		return $stored;
	}//end sendMessage()



	/**
	 * Log what became of the send, at the level the outcome deserves.
	 *
	 * @param string               $caseId The case.
	 * @param array<string, mixed> $result What the adapter answered.
	 *
	 * @return void
	 */
	private function logSendOutcome(string $caseId, array $result): void {
		if ((($result['refused'] ?? false) === true)) {
			$this->logger->warning(
				'Dossiq: digital post was not sent',
				['caseId' => $caseId, 'reason' => (string)($result['error'] ?? '')]
			);

			return;
		}

		$this->logger->info(
			'Dossiq: Berichtenbox message sent',
			[
				'caseId' => $caseId,
				'messageId' => $result['messageId'] ?? '',
			]
		);
	}//end logSendOutcome()

	/**
	 * Record what became of a letter integriq is tracking.
	 *
	 * Called by {@see \OCA\Dossiq\Listener\DigitalPostDeliveredListener} on
	 * integriq's status event. The event fires on EVERY status change, `failed`
	 * and `read` included, so this writes whatever it is told rather than only
	 * the happy path: a letter that failed and a letter nobody has opened are
	 * two different things a handler needs to see, and neither is `sent`.
	 *
	 * @param string $externalMessageId The id integriq tracks the message by.
	 * @param string $status            The status it moved to.
	 * @param string $lastError         The provider's reason, when it failed.
	 * @param bool   $simulated         Whether the binding that handled it sends nothing.
	 *
	 * @return bool True when a stored message was updated.
	 *
	 * @SuppressWarnings(PHPMD.BooleanArgumentFlag) `$simulated` decides nothing.
	 *  It is written straight onto the stored message as `simulated` and is never
	 *  read in a condition anywhere in this method, so there is no second
	 *  responsibility to split out: a `recordSimulatedDeliveryStatus()` twin
	 *  would be this method again with one literal changed. The value is data
	 *  about which binding handled the letter, which is exactly what a handler
	 *  reading the message needs to see.
	 *
	 * @spec openspec/specs/berichtenbox-integration/spec.md
	 */
	public function recordDeliveryStatus(
		string $externalMessageId,
		string $status,
		string $lastError = '',
		bool $simulated = false,
	): bool {
		if (trim($externalMessageId) === '' || trim($status) === '') {
			return false;
		}

		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return false;
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('berichtenbox_message_schema');

		$data = $this->storedMessage(
			objectService: $objectService,
			externalMessageId: $externalMessageId,
			register: (int)$register,
			schema: (int)$schema,
		);
		if ($data === null) {
			return false;
		}

		$data['status'] = $status;
		$data['lastError'] = $lastError;
		$data['simulated'] = $simulated;
		if ($status === 'read') {
			$data['readAt'] = (new DateTime())->format('c');
		}

		$objectService->saveObject(object: $data, register: (int)$register, schema: (int)$schema);

		$this->journal->recordStatus(
			data: $data,
			externalMessageId: $externalMessageId,
			status: $status,
			lastError: $lastError,
		);

		return true;
	}//end recordDeliveryStatus()

	/**
	 * The stored message integriq is tracking under this external id, as an array.
	 *
	 * Integriq tracks messages for every app on the instance. One we did not
	 * send is not ours to record, and it is not an error, so an absent row and
	 * a row in a shape this cannot read both answer null.
	 *
	 * @param object $objectService     The OpenRegister object service.
	 * @param string $externalMessageId The id integriq tracks the message by.
	 * @param int    $register          The register the messages live in.
	 * @param int    $schema            The message schema.
	 *
	 * @return array<string, mixed>|null The stored message, or null.
	 */
	private function storedMessage(
		object $objectService,
		string $externalMessageId,
		int $register,
		int $schema,
	): ?array {
		$matches = $objectService->findAll(
			[
				'filters' => [
					'register' => $register,
					'schema' => $schema,
					'externalMessageId' => $externalMessageId,
				],
			],
		);

		if ($matches === []) {
			return null;
		}

		$data = $matches[0];
		if (is_object($data) === true && method_exists($data, 'jsonSerialize') === true) {
			$data = $data->jsonSerialize();
		}

		if (is_array($data) === false) {
			return null;
		}

		return $data;
	}//end storedMessage()



	/**
	 * Get sent messages for a case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, mixed> List of stored Berichtenbox messages for the case.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getMessagesForCase(string $caseId): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('berichtenbox_message_schema');

		return $objectService->findAll(
			['filters' => ['register' => (int)$register, 'schema' => (int)$schema, 'caseId' => $caseId]],
		);
	}//end getMessagesForCase()

	/**
	 * Get all messages whose read-status still needs to be polled.
	 *
	 * Returns messages with status 'sent' or 'unread_flagged' that carry an
	 * externalMessageId (i.e. they were actually delivered to Berichtenbox).
	 *
	 * @return array<int, mixed> List of pending message records.
	 *
	 * @spec openspec/specs/berichtenbox-integration/spec.md
	 */
	public function getPendingMessages(): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('berichtenbox_message_schema');

		$sent = $objectService->findAll(
			['filters' => ['register' => (int)$register, 'schema' => (int)$schema, 'status' => 'sent']],
		);

		$flagged = $objectService->findAll(
			['filters' => ['register' => (int)$register, 'schema' => (int)$schema, 'status' => 'unread_flagged']],
		);

		return array_merge($sent, $flagged);
	}//end getPendingMessages()

	/**
	 * Resolve the case a stored message belongs to.
	 *
	 * `poll()` takes only a message id, so there is nothing in its signature to
	 * authorise against. This resolves the owning case so the controller can
	 * apply the same per-case guard as the rest of the file. It returns null —
	 * which the caller treats as DENY — whenever the message cannot be
	 * resolved, so an unknown id is not an existence oracle either.
	 *
	 * @param string $messageId The OpenRegister message UUID.
	 *
	 * @return string|null The owning case UUID, or null when unresolvable.
	 *
	 * @spec openspec/specs/authz-bypass-fixes/spec.md
	 */
	public function getCaseIdForMessage(string $messageId): ?string {
		return $this->owningCase->resolve(
			objectId: $messageId,
			schemaKey: 'berichtenbox_message_schema',
			caseField: 'caseId',
		);
	}//end getCaseIdForMessage()

	/**
	 * Poll read status for a message.
	 *
	 * @param string $messageId The OpenRegister message UUID.
	 *
	 * @return array<string, mixed> The message record, possibly updated with read status.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function pollReadStatus(string $messageId): array {
		$objectService = $this->getObjectService();
		if ($objectService === null) {
			return ['error' => 'OpenRegister not available'];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('berichtenbox_message_schema');

		$message = $objectService->find($messageId, register: (int)$register, schema: (int)$schema);
		$data = $message->jsonSerialize();

		if (empty($data['externalMessageId']) === true) {
			return $data;
		}

		$status = $this->adapter->getReadStatus($data['externalMessageId']);

		// 🔴 AN ADAPTER THAT CANNOT ANSWER MUST NOT MOVE THE RECORD. The
		// integriq adapter reports status by EVENT, not by poll, so it answers
		// `unknown` rather than inventing a read flag. Falling through here
		// would walk every such message into `unread_flagged` after seven days
		// on the strength of a question nobody asked, and a case would read
		// "the citizen has not opened this" when the truth is "we did not
		// check". The poll simply records that it looked.
		if (($status['unknown'] ?? false) === true) {
			$data['readPolledAt'] = (new DateTime())->format('c');
			$objectService->saveObject(object: $data, register: (int)$register, schema: (int)$schema);

			return $data;
		}

		$data['readPolledAt'] = (new DateTime())->format('c');

		if (($status['read'] ?? false) === true) {
			$data['status'] = 'read';
			$data['readAt'] = $status['readAt'];
			$objectService->saveObject(object: $data, register: (int)$register, schema: (int)$schema);

			return $data;
		}

		// Check if unread for > 7 days.
		if (empty($data['sentAt']) === false) {
			$sentAt = new DateTime($data['sentAt']);
			$diff = (new DateTime())->diff($sentAt)->days;
			if ($diff >= 7 && $data['status'] !== 'unread_flagged') {
				$data['status'] = 'unread_flagged';
			}
		}

		$objectService->saveObject(object: $data, register: (int)$register, schema: (int)$schema);

		return $data;
	}//end pollReadStatus()

	/**
	 * Validate a BSN using the 11-proef.
	 *
	 * @param string $bsn The BSN to validate.
	 *
	 * @return bool True when the BSN is a 9-digit number passing the 11-proef.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function validateBsn(string $bsn): bool {
		if (preg_match('/^\d{9}$/', $bsn) !== 1) {
			return false;
		}

		$sum = 0;
		for ($i = 0; $i < 8; $i++) {
			$sum += (int)$bsn[$i] * (9 - $i);
		}

		$sum -= (int)$bsn[8];

		return ($sum % 11) === 0 && $sum !== 0;
	}//end validateBsn()

	/**
	 * Validate message inputs.
	 *
	 * @param string $bsn Citizen BSN.
	 * @param string $subject Message subject.
	 * @param string $body Plain text message body.
	 *
	 * @return array<int, string> List of validation error messages, empty when valid.
	 */
	private function validateMessage(string $bsn, string $subject, string $body): array {
		$errors = [];

		if (empty($bsn) === true) {
			$errors[] = 'BSN is verplicht voor berichten via Mijn Overheid';
		} elseif ($this->validateBsn(bsn: $bsn) === false) {
			$errors[] = 'Ongeldig BSN-nummer';
		}

		if (empty($subject) === true) {
			$errors[] = 'Onderwerp is verplicht';
		}

		if (empty($body) === true) {
			$errors[] = 'Berichttekst is verplicht';
		}

		// Strip HTML from body (plain text only).
		if ($body !== strip_tags($body)) {
			$errors[] = 'Berichttekst mag alleen platte tekst bevatten';
		}

		return $errors;
	}//end validateMessage()

	/**
	 * Resolve the OpenRegister ObjectService if OpenRegister is installed.
	 *
	 * @return \OCA\OpenRegister\Contract\ObjectServiceInterface|null The object service or null.
	 */
	private function getObjectService(): ?\OCA\OpenRegister\Contract\ObjectServiceInterface {
		if (in_array('openregister', $this->appManager->getInstalledApps()) === false) {
			return null;
		}

		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Exception $e) {
			$this->logger->error('Dossiq: Could not get ObjectService', ['exception' => $e->getMessage()]);
			return null;
		}
	}//end getObjectService()
}//end class
