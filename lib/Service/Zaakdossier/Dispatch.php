<?php

/**
 * Dossiq Dispatch
 *
 * One dispatch row a document's correspondent gets.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
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
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

/**
 * One party in one role on one document of a case.
 *
 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-014-a-dispatch-record-is-written-per-correspondent
 */
final class Dispatch {
	/**
	 * Constructor.
	 *
	 * @param string $caseId     The case.
	 * @param string $documentId The informatieobject uuid.
	 * @param string $party      The party's identifier.
	 * @param string $role       `afzender` or `geadresseerde`.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $documentId,
		public readonly string $party,
		public readonly string $role,
	) {
	}//end __construct()

	/**
	 * The filters that find this dispatch when the document already has it.
	 *
	 * @return array<string, mixed> The filters.
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-014-a-dispatch-record-is-written-per-correspondent
	 */
	public function lookup(): array {
		return [
			'document' => $this->documentId,
			'involvedParty' => $this->party,
			'relationshipType' => $this->role,
			'_limit' => 1,
		];
	}//end lookup()

	/**
	 * The row this dispatch is stored as, dated today.
	 *
	 * @param string $today Today as Y-m-d.
	 *
	 * @return array<string, string> The row.
	 *
	 * @spec openspec/changes/document-correspondents/specs/document-zaakdossier/spec.md#requirement-req-zak-014-a-dispatch-record-is-written-per-correspondent
	 */
	public function row(string $today): array {
		$row = [
			'document' => $this->documentId,
			'case' => $this->caseId,
			'involvedParty' => $this->party,
			'relationshipType' => $this->role,
		];
		// The date says which act this row records, and only one of the
		// two is true: a sender was received from, an addressee was sent
		// to. Writing both would make every dispatch read as a round trip.
		if ($this->role === DocumentCorrespondents::ROLE_SENDER) {
			$row['receiveDate'] = $today;
		}

		if ($this->role === DocumentCorrespondents::ROLE_RECIPIENT) {
			$row['sendDate'] = $today;
		}

		return $row;
	}//end row()
}//end class
