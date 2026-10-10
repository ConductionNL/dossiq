<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Service\Documents\ScanVerdictReader;

/**
 * Puts the scan verdict and the approval marker on every dossier row.
 *
 * The Files tab draws its Scan and Approval columns from the dossier listing
 * (`source: row`, keyed by `fileId`), one request per folder. Without these
 * two values on the row the columns would go blank, or each row would fetch
 * its own verdict and marker: forty rows, eighty requests. So the listing
 * carries them, read in one scanner query and one decidiq lookup.
 *
 * It runs on rows the caller may already see: the clearance filter comes
 * first, so a marker never names a route on a document the caller may not
 * read.
 *
 * @spec openspec/changes/documents-on-the-case/tasks.md#2.2
 */
class DossierRowAnnotator {

	/**
	 * Constructor.
	 *
	 * @param ScanVerdictReader $verdicts What the virus scanner recorded per file.
	 * @param DocumentApprovalClearance $approvals What decidiq says per document.
	 */
	public function __construct(
		private readonly ScanVerdictReader $verdicts,
		private readonly DocumentApprovalClearance $approvals,
	) {
	}//end __construct()

	/**
	 * The rows, each with `scanVerdict` and `approvalMarker` added.
	 *
	 * `scanVerdict` is the verdict object the scan endpoint answers, Not
	 * scanned for a row without a file. `approvalMarker` is the marker the
	 * approval-markers endpoint answers, or null for a document nobody routed.
	 *
	 * @param array<int, array<string, mixed>> $documents The dossier rows.
	 *
	 * @return array<int, array<string, mixed>> The same rows, annotated, in order.
	 *
	 * @spec openspec/changes/documents-on-the-case/tasks.md#2.2
	 */
	public function annotate(array $documents): array {
		if ($documents === []) {
			return [];
		}

		$fileIds = [];
		$documentIds = [];
		foreach ($documents as $document) {
			$fileIds[] = (int)($document['fileId'] ?? 0);
			$documentIds[] = $this->documentId(document: $document);
		}

		$verdicts = $this->verdicts->verdictsFor(fileIds: $fileIds);
		$clearances = $this->approvals->forDocuments(documentIds: $documentIds);

		$annotated = [];
		foreach ($documents as $index => $document) {
			$fileId = $fileIds[$index];
			$clearance = ($clearances[$documentIds[$index]] ?? DocumentApprovalClearance::NOT_ROUTED);

			$document['scanVerdict'] = ($verdicts[$fileId] ?? $this->verdicts->verdictFor(fileId: 0));
			$document['approvalMarker'] = $this->approvals->markerFor(clearance: $clearance);
			$annotated[] = $document;
		}

		return $annotated;
	}//end annotate()

	/**
	 * The marker of one document, or null when nobody routed it.
	 *
	 * @param string $documentId The informatieobject UUID.
	 *
	 * @return array{routed: true, cleared: bool, waitingOn: array<int, array<string, mixed>>, reason: string}|null
	 *         The marker, or null.
	 *
	 * @spec openspec/specs/besluitvorming-leaf/spec.md
	 */
	public function markerForDocument(string $documentId): ?array {
		return $this->approvals->markerFor(clearance: $this->approvals->forDocument(documentId: $documentId));
	}//end markerForDocument()

	/**
	 * The document's own id, from the row or its `@self`.
	 *
	 * @param array<string, mixed> $document The dossier row.
	 *
	 * @return string The id, or '' when the row names none.
	 *
	 * @spec openspec/changes/documents-on-the-case/tasks.md#2.2
	 */
	private function documentId(array $document): string {
		$id = ($document['id'] ?? ($document['@self']['id'] ?? ''));
		if (is_scalar($id) === false) {
			return '';
		}

		return trim((string)$id);
	}//end documentId()
}//end class
