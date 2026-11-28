<?php

/**
 * Dossiq WOO Document Assessment Service
 *
 * Service for per-document WOO disclosure assessments. Manages wooAssessment
 * records (openbaar / deels openbaar / niet openbaar) with mandatory
 * weigeringsgrond validation per WOO Art. 5.1/5.2. Guards stage advancement
 * until every collected document has been assessed.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\DocumentRelevanceInterface;
use OCA\Dossiq\Kernel\Contracts\WooCaseDocumentsInterface;
use OCA\Dossiq\Kernel\Contracts\WOODocumentAssessmentServiceInterface;
use OCA\Dossiq\Review\OutstandingDocuments;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\Assessment\AssessmentBatchWriter;
use OCA\Dossiq\Service\Assessment\AssessmentRecords;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for WOO per-document disclosure assessments.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class WOODocumentAssessmentService implements WOODocumentAssessmentServiceInterface {

	/**
	 * The documents of a case that still need a verdict.
	 *
	 * @var OutstandingDocuments
	 */
	private readonly OutstandingDocuments $outstandingDocuments;

	/**
	 * Validates, stamps and stores a batch of assessments.
	 *
	 * @var AssessmentBatchWriter
	 */
	private readonly AssessmentBatchWriter $batchWriter;

	/**
	 * Finds, reads and changes the stored assessments.
	 *
	 * @var AssessmentRecords
	 */
	private readonly AssessmentRecords $records;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param IUserSession $userSession Current user session
	 * @param LoggerInterface $logger Logger
	 * @param WooCaseDocumentsInterface|null $caseDocuments Where a case's documents are: its informatieobjecten.
	 * @param WooRefusalGrounds|null $refusalGrounds The settled list a cited ground is checked against.
	 *        Built over the same settings and logger when left out, because those are its only
	 *        collaborators and a default built from them is the instance the container wires.
	 * @param CaseReviewerAccess|null $reviewerAccess Who may change an assessment and who reads it,
	 *        when the case type keeps its assessments private to the case (case-reviewer-access).
	 * @param DocumentRelevanceInterface|null $reviews Each document's relevance and pages seen (woo-review-triage);
	 *        null keeps every document needing a verdict.
	 * @param OutstandingDocuments|null $outstanding The review's outstanding documents, as the container builds them
	 *        (with the case type's drafted document types left out); null builds one from the collaborators above.
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		?WooCaseDocumentsInterface $caseDocuments = null,
		?WooRefusalGrounds $refusalGrounds = null,
		?CaseReviewerAccess $reviewerAccess = null,
		?DocumentRelevanceInterface $reviews = null,
		?OutstandingDocuments $outstanding = null,
	) {
		$refusalGrounds = ($refusalGrounds ?? new WooRefusalGrounds(settingsService: $settingsService, logger: $logger));
		$this->batchWriter = new AssessmentBatchWriter(
			settingsService: $settingsService,
			logger: $logger,
			refusalGrounds: $refusalGrounds,
			reviewerAccess: $reviewerAccess,
			reviews: $reviews
		);
		$this->records = new AssessmentRecords(settingsService: $settingsService, userSession: $userSession, reviewerAccess: $reviewerAccess);
		$this->outstandingDocuments = ($outstanding ?? new OutstandingDocuments(
			settingsService: $settingsService,
			caseDocuments: $caseDocuments,
			reviews: $reviews
		));
	}//end __construct()

	/**
	 * Bulk-upsert assessments for a case's documents.
	 *
	 * Creates or updates wooAssessment records. Returns the list of saved
	 * assessments and flags any documents that still lack an assessment.
	 *
	 * @param string $caseId The case UUID
	 * @param array<int, array<string, mixed>> $assessments Array of assessment payloads
	 *
	 * @return array<string, mixed> Result with saved assessments and outstanding documents
	 *
	 * @throws RuntimeException If OpenRegister unavailable
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-reviewer-changes-only-the-records-they-made-req-cra-003
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function bulkUpsert(string $caseId, array $assessments): array {
		$tally = $this->batchWriter->write(
			caseId: $caseId,
			assessments: $assessments,
			userId: ($this->userSession->getUser()?->getUID() ?? 'system')
		);

		$outstanding = $this->getOutstanding(caseId: $caseId);

		$this->logger->info(
			'WOO bulk-upsert: ' . count($tally['saved']) . ' saved, ' . $outstanding['count'] . ' outstanding for case ' . $caseId,
			['app' => AppIdentity::APP_ID],
		);

		return ['saved' => $tally['saved'], 'errors' => $tally['errors'], 'outstanding' => $outstanding];
	}//end bulkUpsert()

	/**
	 * Validate a single assessment payload.
	 *
	 * @param array<string, mixed> $assessment The assessment to validate
	 *
	 * @return array<string, string> Validation errors keyed by field name; empty if valid
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
	 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-dossiq-validates-assessments-against-the-list-req-wrg-005
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function validate(array $assessment): array {
		return $this->batchWriter->validate(assessment: $assessment);
	}//end validate()

	/**
	 * Get documents without a completed assessment for a case.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return array<string, mixed> Array with 'count' and 'documents' list of unassessed doc IDs
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function getOutstanding(string $caseId): array {
		return $this->outstandingDocuments->forCase(caseId: $caseId);
	}//end getOutstanding()

	/**
	 * Every document of a case that is under review: on the case and not set aside.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return list<string> The document identifiers.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-every-document-with-its-assessment-req-das-004
	 */
	public function getDocumentsUnderReview(string $caseId): array {
		return $this->outstandingDocuments->underReview(caseId: $caseId);
	}//end getDocumentsUnderReview()

	/**
	 * Check whether all documents in a case have been assessed.
	 *
	 * Used as a stage-advancement guard before "Lakken / Anonimiseren".
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return bool True if all documents are assessed
	 *
	 * @spec openspec/changes/archive/2026-06-13-woo-case-type/tasks.md#task-5
	 */
	public function allDocumentsAssessed(string $caseId): bool {
		$outstanding = $this->getOutstanding(caseId: $caseId);
		return ($outstanding['count'] === 0);
	}//end allDocumentsAssessed()

	/**
	 * Load the existing wooAssessment record for a (case, document) pair, or
	 * null when the document has not been assessed yet — the SAME
	 * search-then-update lookup `bulkUpsert()` already performs, extracted
	 * so `WOOAnonymisationAssistService` can find the record it attaches a
	 * `redactionProposal` to without duplicating the OpenRegister query
	 * shape (woo-llm-anonymisation).
	 *
	 * @param string $caseId The case UUID.
	 * @param string $documentRef The document UUID.
	 *
	 * @return array<string, mixed>|null The assessment record, or null if not yet assessed.
	 *
	 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-1
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function findAssessment(string $caseId, string $documentRef): ?array {
		return $this->records->find(caseId: $caseId, documentRef: $documentRef);
	}//end findAssessment()

	/**
	 * The assessments of a case that judged their document partly public, as far as the caller may read them.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, mixed>> The assessments.
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function partlyPublicAssessments(string $caseId): array {
		return $this->records->partlyPublic(caseId: $caseId);
	}//end partlyPublicAssessments()

	/**
	 * The partly public documents of a case that have no redacted copy yet.
	 *
	 * Read regardless of the caller, like the outstanding list (REQ-WSA-004):
	 * an assessment the caller may not read still holds up the stage it is in.
	 * Only document references are answered.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array{partlyPublic: int, waiting: list<string>} How many documents are partly public, and which still wait.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md#requirement-a-move-may-require-every-partly-public-document-redacted
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function partlyPublicWithoutCopy(string $caseId): array {
		return $this->records->partlyPublicWithoutCopy(caseId: $caseId);
	}//end partlyPublicWithoutCopy()

	/**
	 * Attach (or update) a `redactionProposal` on an EXISTING wooAssessment
	 * record — the document must already have a disclosure classification
	 * (business rule: assess first, then request redaction assistance).
	 * Never creates a new assessment record and never touches
	 * `classification`/`weigeringsgronden` (woo-llm-anonymisation).
	 *
	 * @param string $caseId The case UUID.
	 * @param string $documentRef The document UUID.
	 * @param array<string, mixed> $proposal `{spans, source, llmAvailable, proposedBy,
	 *                                       proposedAt, status}` — see
	 *                                       `WOOAnonymisationAssistService::proposeSpans()`.
	 *
	 * @return array<string, mixed> The updated assessment record.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or the document has not
	 *                          yet been assessed.
	 * @throws RefusedException (403 `owned-by-another-reviewer`) When the case type keeps
	 *                          assessments private and the caller neither made this one nor supervises.
	 *
	 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-1
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-reviewer-changes-only-the-records-they-made-req-cra-003
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function saveRedactionProposal(string $caseId, string $documentRef, array $proposal): array {
		$savedObject = $this->records->update(caseId: $caseId, documentRef: $documentRef, fields: ['redactionProposal' => $proposal]);

		$this->logger->info(
			'WOO redaction proposal saved for document ' . $documentRef . ' in case ' . $caseId
			. ' (status: ' . ($proposal['status'] ?? 'unknown') . ')',
			['app' => AppIdentity::APP_ID],
		);

		return $savedObject;
	}//end saveRedactionProposal()

	/**
	 * Record the redaction of a document on its EXISTING assessment.
	 *
	 * Writes the `redaction` record and, when given, the redacted copy the
	 * publication delivers in place of the original. The same author,
	 * supervisor or administrator rule holds as for the verdict (REQ-CRA-003).
	 *
	 * @param string               $caseId              The case UUID.
	 * @param string               $documentRef         The document UUID.
	 * @param array<string, mixed> $redaction           The redaction record.
	 * @param string|null          $redactedDocumentRef The redacted copy, or null to leave it as it is.
	 *
	 * @return array<string, mixed> The updated assessment record.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or the document has not been assessed.
	 * @throws RefusedException (403 `owned-by-another-reviewer`) When the caller may not change this assessment.
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-a-redacted-copy-is-written-only-after-a-named-officer-checked-the-entities-req-drd-002
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function saveRedaction(string $caseId, string $documentRef, array $redaction, ?string $redactedDocumentRef = null): array {
		$fields = ['redaction' => $redaction];
		if ($redactedDocumentRef !== null) {
			$fields['redactedDocumentRef'] = $redactedDocumentRef;
		}

		return $this->records->update(caseId: $caseId, documentRef: $documentRef, fields: $fields);
	}//end saveRedaction()
}//end class
