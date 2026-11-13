<?php

/**
 * Contract of BerichtenboxService
 *
 * The public surface of BerichtenboxService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of BerichtenboxService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BerichtenboxServiceInterface {

	/**
	 * Get sent messages for a case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, mixed> List of stored Berichtenbox messages for the case.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getMessagesForCase(string $caseId): array;

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
	): bool;

	/**
	 * Send a message to the Berichtenbox.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $bsn Citizen BSN.
	 * @param string $subject Message subject.
	 * @param string $body Plain text message body.
	 * @param string $typeCode Bericht type code.
	 * @param string|null $attachmentFileId Optional Nextcloud file ID of the attachment.
	 * @param string $category What the letter is: `case-update` (default), `besluit` or `statutory`.
	 *
	 * @return array<string, mixed> The stored message record or an error payload.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 * @spec openspec/specs/case-message-opt-out/spec.md#requirement-digital-post-carries-a-category-to-integriq-req-coo-004
	 */
	public function sendMessage(
		string $caseId,
		string $bsn,
		string $subject,
		string $body,
		string $typeCode,
		?string $attachmentFileId = null,
		string $category = 'case-update',
	): array;
}//end interface
