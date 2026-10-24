<?php

/**
 * Dossiq document case write guard
 *
 * A write to a document (its metadata, its status) needs the change right on
 * EVERY case the document belongs to: a document shared by two cases is part
 * of both, and the right on one case is not a right on the other. The cases
 * are read regardless of the caller, because a case the caller cannot see is
 * exactly the one that must refuse. A document on no case keeps the read
 * clearance as its only rule.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/document-writes-need-every-case/specs/document-writes-need-every-case/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCP\IUser;

/**
 * Names the case a document write is refused on, if any.
 *
 * @spec openspec/changes/document-writes-need-every-case/specs/document-writes-need-every-case/spec.md#requirement-a-document-write-needs-the-change-right-on-every-case-of-the-document
 */
class DocumentCaseWriteGuard {

	/**
	 * Constructor.
	 *
	 * @param DocumentRecordStore  $store   The cases a document belongs to.
	 * @param DossierUploadHandler $uploads The change right on a case, as an upload checks it.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DocumentRecordStore $store,
		private readonly DossierUploadHandler $uploads,
	) {
	}//end __construct()

	/**
	 * The first case of the document the caller may not change, or null.
	 *
	 * @param IUser  $user       The caller.
	 * @param string $documentId The document (informatieobject) id.
	 *
	 * @return string|null The refused case id, null when every case allows the write or there is none.
	 *
	 * @spec openspec/changes/document-writes-need-every-case/specs/document-writes-need-every-case/spec.md#requirement-a-document-write-needs-the-change-right-on-every-case-of-the-document
	 */
	public function refusedCase(IUser $user, string $documentId): ?string {
		foreach ($this->store->caseIdsOf(recordId: $documentId) as $caseId) {
			if ($this->uploads->hasCaseUploadAccess(user: $user, caseId: $caseId) === false) {
				return $caseId;
			}
		}

		return null;
	}//end refusedCase()

	/**
	 * The first document of a batch whose write is refused, with its case, or null.
	 *
	 * @param IUser        $user        The caller.
	 * @param array<mixed> $documentIds The document ids.
	 *
	 * @return array{documentId: string, caseId: string}|null The refusal, null when every write is allowed.
	 *
	 * @spec openspec/changes/document-writes-need-every-case/specs/document-writes-need-every-case/spec.md#requirement-a-bulk-document-write-is-refused-whole
	 */
	public function firstRefused(IUser $user, array $documentIds): ?array {
		foreach ($documentIds as $documentId) {
			$caseId = $this->refusedCase(user: $user, documentId: (string)$documentId);
			if ($caseId !== null) {
				return ['documentId' => (string)$documentId, 'caseId' => $caseId];
			}
		}

		return null;
	}//end firstRefused()
}//end class
