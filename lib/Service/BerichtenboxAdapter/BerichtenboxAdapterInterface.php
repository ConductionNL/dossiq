<?php

/**
 * Dossiq Berichtenbox Adapter Interface.
 *
 * Contract for Mijn Overheid Berichtenbox API adapter implementations.
 *
 * @category Interface
 * @package  OCA\Dossiq\Service\BerichtenboxAdapter
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/berichtenbox-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\BerichtenboxAdapter;

/**
 * Interface for Mijn Overheid Berichtenbox API adapters.
 */
interface BerichtenboxAdapterInterface {
	/**
	 * Send a message to the Berichtenbox.
	 *
	 * @param string $bsn Citizen BSN
	 * @param string $subject Message subject
	 * @param string $body Plain text message body
	 * @param string $typeCode Bericht type code
	 * @param string|null $attachment PDF attachment content (base64)
	 * @param string $category What the letter is: `case-update`, `besluit` or `statutory` (opt-out-before-send)
	 * @param string $caseRef The case the letter is about, so a case opt-out can match
	 *
	 * @return array Result with messageId, status
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 * @spec openspec/changes/opt-out-before-send/specs/case-message-opt-out/spec.md#requirement-digital-post-carries-a-category-to-integriq-req-coo-004
	 */
	public function sendMessage(
		string $bsn,
		string $subject,
		string $body,
		string $typeCode,
		?string $attachment = null,
		string $category = 'case-update',
		string $caseRef = '',
	): array;
}//end interface
