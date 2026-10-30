<?php

/**
 * Dossiq document write gate
 *
 * What a document write on the dossier endpoints passes before it runs: the
 * change right on every case the document belongs to, and for a bulk
 * transition the read clearance on every selected document. Each check
 * answers with the response that refuses the write, or null. The bulk
 * metadata run happens here too, behind its gate.
 *
 * Kept out of {@see \OCA\Dossiq\Controller\ZaakdossierController}, which was
 * over its coupling ceiling: the controller reads the request and the gate
 * decides whether the write may start.
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
 * @spec openspec/specs/document-writes-need-every-case/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IUser;

/**
 * Refuses a document write before it runs, or lets it through.
 *
 * @spec openspec/specs/document-writes-need-every-case/spec.md
 */
class DocumentWriteGate {

	/**
	 * Constructor.
	 *
	 * @param DocumentCaseWriteGuard $caseWrites The change right on every case a document belongs to.
	 * @param BulkDocumentActions    $bulk       The clearance gate over a selection, and the bulk metadata run.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly DocumentCaseWriteGuard $caseWrites,
		private readonly BulkDocumentActions $bulk,
	) {
	}//end __construct()

	/**
	 * The refusal of a write to one document, or null when every case allows it.
	 *
	 * @param IUser  $user       The caller.
	 * @param string $documentId The document (informatieobject) id.
	 *
	 * @return JSONResponse|null 403 naming the document and the refused case, or null.
	 *
	 * @spec openspec/specs/document-writes-need-every-case/spec.md#requirement-a-document-write-needs-the-change-right-on-every-case-of-the-document
	 */
	public function refusedWrite(IUser $user, string $documentId): ?JSONResponse {
		$caseId = $this->caseWrites->refusedCase(user: $user, documentId: $documentId);
		if ($caseId === null) {
			return null;
		}

		return $this->caseWriteRefusal(documentId: $documentId, caseId: $caseId);
	}//end refusedWrite()

	/**
	 * The refusal of a bulk metadata change, or null when every case of every document allows it.
	 *
	 * @param IUser        $user The caller.
	 * @param array<mixed> $ids  The document ids.
	 *
	 * @return JSONResponse|null 403 naming the first refused document and its case, or null.
	 *
	 * @spec openspec/specs/document-writes-need-every-case/spec.md#requirement-a-bulk-document-write-is-refused-whole
	 */
	public function refusedBulkMetadata(IUser $user, array $ids): ?JSONResponse {
		$refused = $this->caseWrites->firstRefused(user: $user, documentIds: $ids);
		if ($refused === null) {
			return null;
		}

		return $this->caseWriteRefusal(documentId: $refused['documentId'], caseId: $refused['caseId']);
	}//end refusedBulkMetadata()

	/**
	 * The refusal of a bulk status transition, or null when it may run.
	 *
	 * The case write check comes first; then every selected document must be
	 * readable, checked before anything is written.
	 *
	 * @param IUser        $user The caller.
	 * @param array<mixed> $ids  The document ids.
	 *
	 * @return JSONResponse|null 403, or null.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function refusedBulkTransition(IUser $user, array $ids): ?JSONResponse {
		$refusal = $this->refusedBulkMetadata(user: $user, ids: $ids);
		if ($refusal !== null) {
			return $refusal;
		}

		if ($this->bulk->allReadable(user: $user, ids: $ids) === false) {
			return new JSONResponse(
				['error' => 'Insufficient clearance for one or more selected documents'],
				Http::STATUS_FORBIDDEN,
			);
		}

		return null;
	}//end refusedBulkTransition()

	/**
	 * Apply one metadata change to every listed document, reporting per id.
	 *
	 * @param IUser                $user     The caller.
	 * @param array<mixed>         $ids      The document ids.
	 * @param array<string, mixed> $metadata The metadata to apply.
	 *
	 * @return array<int, array<string, mixed>> One result entry per id, in order.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function updateAll(IUser $user, array $ids, array $metadata): array {
		return $this->bulk->updateAll(user: $user, ids: $ids, metadata: $metadata);
	}//end updateAll()

	/**
	 * The refusal of a document write: the document and the case it was refused on.
	 *
	 * @param string $documentId The document.
	 * @param string $caseId     The case the caller may not change.
	 *
	 * @return JSONResponse 403.
	 */
	private function caseWriteRefusal(string $documentId, string $caseId): JSONResponse {
		return new JSONResponse(
			[
				'error' => 'You may not change a case this document belongs to; nothing was changed',
				'documentId' => $documentId,
				'caseId' => $caseId,
			],
			Http::STATUS_FORBIDDEN
		);
	}//end caseWriteRefusal()

	/**
	 * Each listing row with `canChangeAccess`, which the Who can see this card offers Change exception by.
	 *
	 * @param IUser                            $user      The caller.
	 * @param array<int, array<string, mixed>> $documents The rows.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-the-who-can-see-this-card-shows-the-exception-and-changes-it
	 */
	public function withChangeRight(IUser $user, array $documents): array {
		return $this->caseWrites->withChangeRight(user: $user, documents: $documents);
	}//end withChangeRight()
}//end class
