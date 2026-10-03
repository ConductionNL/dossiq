<?php

/**
 * Who a portal inbox message goes to in the government message box.
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
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/portal-message-box-recipient/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Portal;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Answers portaliq's `messageBox.recipientProvider` method (dossiq#3192).
 *
 * Portaliq holds no citizen service number, on purpose, so the case app that
 * wrote the message names the recipient, and portaliq passes it straight into
 * integriq's DigitalPostSendRequestedEvent without storing it. The answer is
 * the applicant's BSN, and only when every one of these holds:
 *  - the message is the organisation's (`direction` `handler_to_citizen`); a
 *    resident's own reply is never posted back to them;
 *  - it names a case (`caseId`) whose applicant is a person
 *    (`initiatorType` `person`) with a BSN that passes the 11-proef;
 *  - it is addressed to that case's applicant (`recipientRef` equals the
 *    case's `portalSubject`), so a message to a representative never reaches
 *    the applicant's box under their number.
 * Anything else answers null, and portaliq sends nothing.
 *
 * NO DOUBLE SEND. Dossiq's own message box letters (the compose dialog through
 * BerichtenboxService, decisions and term notices through
 * BerichtenboxRoutingService) go out directly and write no portaalBericht, so
 * no inbox message is one dossiq already delivered. A path that ever writes
 * both must make this method answer null for its messages.
 *
 * THE READ RUNS AS THE SYSTEM. Portaliq calls this from a background job with
 * no Nextcloud user, where OpenRegister's RBAC would answer nothing. The
 * message id is the only input.
 *
 * @spec openspec/changes/portal-message-box-recipient/tasks.md
 */
class PortalMessageBoxRecipient {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService OpenRegister access and the configured register and schemas.
	 * @param LoggerInterface $logger          Logger; never given the identity.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The applicant's BSN for one inbox message, or null.
	 *
	 * @param string $messageId The portaalBericht uuid.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/portal-message-box-recipient/tasks.md
	 */
	public function forMessage(string $messageId): ?string {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$messageSchema = $this->settingsService->getConfigValue('portaal_bericht_schema');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		if ($messageId === '' || $objectService === null || $register === '' || $messageSchema === '' || $caseSchema === '') {
			return null;
		}

		try {
			$recipient = $this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): ?string => $this->recipientOf(
					objectService: $objectService,
					schemas: ['register' => $register, 'message' => $messageSchema, 'case' => $caseSchema],
					messageId: $messageId
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the message box recipient could not be read', ['exception' => get_class($e)]);
			return null;
		}

		if (is_string($recipient) === false) {
			return null;
		}

		return $recipient;
	}//end forMessage()

	/**
	 * Read the message and its case, inside the system context.
	 *
	 * @param object                $objectService The OpenRegister object service.
	 * @param array<string, string> $schemas       `register`, `message` and `case`.
	 * @param string                $messageId     The portaalBericht uuid.
	 *
	 * @return string|null
	 */
	private function recipientOf(object $objectService, array $schemas, string $messageId): ?string {
		$message = $this->findObjectAsArray(objectService: $objectService, register: $schemas['register'], schema: $schemas['message'], id: $messageId);
		if ($message === null || ($message['direction'] ?? '') !== 'handler_to_citizen') {
			return null;
		}

		$caseId = (string)($message['caseId'] ?? '');
		if ($caseId === '') {
			return null;
		}

		$case = $this->findObjectAsArray(objectService: $objectService, register: $schemas['register'], schema: $schemas['case'], id: $caseId);
		return $this->applicantBsn(message: $message, case: $case);
	}//end recipientOf()

	/**
	 * The applicant's BSN when the message is addressed to them, else null.
	 *
	 * @param array<string, mixed>      $message The portaalBericht.
	 * @param array<string, mixed>|null $case    The case it names.
	 *
	 * @return string|null
	 */
	private function applicantBsn(array $message, ?array $case): ?string {
		if ($case === null || ($case['initiatorType'] ?? '') !== 'person') {
			return null;
		}

		$subject = (string)($case['portalSubject'] ?? '');
		if ($subject === '' || (string)($message['recipientRef'] ?? '') !== $subject) {
			return null;
		}

		$bsn = trim((string)($case['initiatorSourceId'] ?? ''));
		if ($this->passesElevenTest(bsn: $bsn) === false) {
			return null;
		}

		return $bsn;
	}//end applicantBsn()

	/**
	 * Whether a value is nine digits passing the BSN 11-proef.
	 *
	 * @param string $bsn The value.
	 *
	 * @return bool
	 */
	private function passesElevenTest(string $bsn): bool {
		if (preg_match('/^\d{9}$/', $bsn) !== 1) {
			return false;
		}

		$sum = 0;
		for ($i = 0; $i < 8; $i++) {
			$sum += ((int)$bsn[$i] * (9 - $i));
		}

		$sum -= (int)$bsn[8];
		return ($sum % 11) === 0 && $sum !== 0;
	}//end passesElevenTest()
}//end class
