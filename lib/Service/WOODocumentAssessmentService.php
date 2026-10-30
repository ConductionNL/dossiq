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
 * @spec openspec/changes/woo-case-type/tasks.md#task-5
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Woo\WooCaseDocuments;
use OCA\Dossiq\Review\DisclosureAssessmentValidator;
use OCA\Dossiq\Review\DocumentRelevance;
use OCA\Dossiq\Review\OutstandingDocuments;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Service for WOO per-document disclosure assessments.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-5
 */
class WOODocumentAssessmentService {

	use SearchesObjects;

	/**
	 * The settled list of Woo refusal grounds.
	 *
	 * @var WooRefusalGrounds
	 */
	private readonly WooRefusalGrounds $refusalGrounds;

	/**
	 * The documents of a case that still need a verdict.
	 *
	 * @var OutstandingDocuments
	 */
	private readonly OutstandingDocuments $outstandingDocuments;

	/**
	 * Checks an assessment before it is stored.
	 *
	 * @var DisclosureAssessmentValidator
	 */
	private readonly DisclosureAssessmentValidator $validator;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param IUserSession $userSession Current user session
	 * @param LoggerInterface $logger Logger
	 * @param WooCaseDocuments|null $caseDocuments Where a case's documents are: its informatieobjecten.
	 * @param WooRefusalGrounds|null $refusalGrounds The settled list a cited ground is checked against.
	 *        Built over the same settings and logger when left out, because those are its only
	 *        collaborators and a default built from them is the instance the container wires.
	 * @param CaseReviewerAccess|null $reviewerAccess Who may change an assessment and who reads it,
	 *        when the case type keeps its assessments private to the case (case-reviewer-access).
	 * @param DocumentRelevance|null $reviews Each document's relevance and pages seen (woo-review-triage); null keeps every document needing a verdict.
	 * @param OutstandingDocuments|null $outstanding The review's outstanding documents, as the container builds them
	 *        (with the case type's drafted document types left out); null builds one from the collaborators above.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		?WooCaseDocuments $caseDocuments = null,
		?WooRefusalGrounds $refusalGrounds = null,
		private readonly ?CaseReviewerAccess $reviewerAccess = null,
		private readonly ?DocumentRelevance $reviews = null,
		?OutstandingDocuments $outstanding = null,
	) {
		$this->refusalGrounds       = ($refusalGrounds ?? new WooRefusalGrounds(settingsService: $settingsService, logger: $logger));
		$this->validator            = new DisclosureAssessmentValidator(refusalGrounds: $this->refusalGrounds);
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
	 * @spec openspec/changes/woo-case-type/tasks.md#task-5
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-reviewer-changes-only-the-records-they-made-req-cra-003
	 */
	public function bulkUpsert(string $caseId, array $assessments): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');
		$assessmentSchema = $this->settingsService->getConfigValue('woo_assessment_schema');

		if (empty($register) === true || empty($assessmentSchema) === true) {
			throw new RuntimeException('WOO assessment schema not configured');
		}

		$userId = ($this->userSession->getUser()?->getUID() ?? 'system');

		$saved = [];
		$errors = [];
		$created = 0;
		$judged = [];

		foreach ($assessments as $assessment) {
			$unseen = [];
			if ($this->reviews !== null) {
				$unseen = $this->reviews->pages()->refusalFor(caseId: $caseId, assessment: $assessment);
			}

			$validationErrors = $this->validate(assessment: $assessment) + $unseen;
			if (empty($validationErrors) === false) {
				$errors[] = [
					'documentRef' => $assessment['documentRef'] ?? 'unknown',
					'errors' => $validationErrors,
				];
				continue;
			}

			$assessment['caseRef'] = $caseId;
			$assessment['groundsListVersion'] = WooRefusalGrounds::LIST_VERSION;
			$assessment['assessedBy'] = $userId;
			$assessment['assessedAt'] = date('Y-m-d\TH:i:s');

			// Find existing assessment for this document in this case. Read
			// regardless of the caller, so an assessment the caller cannot see
			// is never assessed a second time (REQ-CRA-003); only its uuid and
			// owner are used, nothing of it is answered.
			$existing = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $assessmentSchema,
				filters: ['caseRef' => $caseId, 'documentRef' => $assessment['documentRef'], '_limit' => 1],
			);

			if (count($existing) > 0) {
				$refusal = $this->reviewerAccess?->refusalFor(
					caseId: $caseId,
					record: $existing[0],
					userId: $userId,
					reference: (string)$assessment['documentRef']
				);
				if ($refusal !== null) {
					$errors[] = $refusal;
					continue;
				}

				$existingId = $existing[0]['id'] ?? $existing[0]['uuid'] ?? null;
				$saved[] = $objectService->saveObject(
					object: $assessment,
					register: $register,
					schema: $assessmentSchema,
					uuid: (string)$existingId,
				);
				$judged[] = (string)$assessment['documentRef'];
				continue;
			}

			$saved[] = $objectService->saveObject(
				object: $assessment,
				register: $register,
				schema: $assessmentSchema,
			);
			$created++;
			$judged[] = (string)$assessment['documentRef'];
		}//end foreach

		$this->reviewerAccess?->reconcileAfterCreate(caseId: $caseId, created: $created);
		$this->markJudgedInScope(caseId: $caseId, documentRefs: $judged, userId: $userId);

		$outstanding = $this->getOutstanding(caseId: $caseId);

		$this->logger->info(
			'WOO bulk-upsert: ' . count($saved) . ' saved, ' . $outstanding['count'] . ' outstanding for case ' . $caseId,
			['app' => Application::APP_ID],
		);

		return [
			'saved' => $saved,
			'errors' => $errors,
			'outstanding' => $outstanding,
		];
	}//end bulkUpsert()

	/**
	 * Mark the documents that just received a verdict in scope, where nobody marked them yet.
	 *
	 * A review that cannot be written leaves the verdict saved and the
	 * document outstanding, which the answer's outstanding list then says.
	 *
	 * @param string       $caseId       The case UUID.
	 * @param list<string> $documentRefs The documents with a saved verdict.
	 * @param string       $userId       Who gave the verdict.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-verdict-marks-its-document-in-scope/specs/document-review/spec.md#requirement-a-verdict-on-an-unmarked-document-marks-it-in-scope-req-wrt-006
	 */
	private function markJudgedInScope(string $caseId, array $documentRefs, string $userId): void {
		if ($this->reviews === null) {
			return;
		}

		try {
			$this->reviews->markInScopeWhereUnmarked(caseId: $caseId, documentRefs: $documentRefs, userId: $userId);
		} catch (RefusedException $e) {
			$this->logger->warning(
				'WOO bulk-upsert: the judged documents could not be marked in scope for case ' . $caseId,
				['app' => Application::APP_ID, 'exception' => $e->getMessage()],
			);
		}
	}//end markJudgedInScope()

	/**
	 * Validate a single assessment payload.
	 *
	 * @param array<string, mixed> $assessment The assessment to validate
	 *
	 * @return array<string, string> Validation errors keyed by field name; empty if valid
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-5
	 * @spec openspec/specs/woo-refusal-grounds/spec.md#requirement-dossiq-validates-assessments-against-the-list-req-wrg-005
	 */
	public function validate(array $assessment): array {
		return $this->validator->validate(assessment: $assessment);
	}//end validate()

	/**
	 * Get documents without a completed assessment for a case.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return array<string, mixed> Array with 'count' and 'documents' list of unassessed doc IDs
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-5
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
	 * @spec openspec/changes/woo-case-type/tasks.md#task-5
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
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-1
	 */
	public function findAssessment(string $caseId, string $documentRef): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$assessmentSchema = $this->settingsService->getConfigValue('woo_assessment_schema');
		if (empty($register) === true || empty($assessmentSchema) === true) {
			return null;
		}

		$existing = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $assessmentSchema,
			filters: [
				'caseRef' => $caseId,
				'documentRef' => $documentRef,
				'_limit' => 1,
			],
		);

		if (count($existing) > 0) {
			return $existing[0];
		}

		return null;
	}//end findAssessment()

	/**
	 * The assessments of a case that judged their document partly public, as far as the caller may read them.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, mixed>> The assessments.
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
	 */
	public function partlyPublicAssessments(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$assessmentSchema = $this->settingsService->getConfigValue('woo_assessment_schema');
		if ($objectService === null || empty($register) === true || empty($assessmentSchema) === true) {
			return [];
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $assessmentSchema,
			filters: ['caseRef' => $caseId, 'classification' => 'deels_openbaar', '_limit' => 500],
		);

		return array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => (string)($row['caseRef'] ?? '') === $caseId
					&& (string)($row['classification'] ?? '') === 'deels_openbaar'
			)
		);
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
	 */
	public function partlyPublicWithoutCopy(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$assessmentSchema = $this->settingsService->getConfigValue('woo_assessment_schema');
		if (empty($assessmentSchema) === true) {
			return ['partlyPublic' => 0, 'waiting' => []];
		}

		$rows = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $this->settingsService->getConfigValue('register'),
			schema: $assessmentSchema,
			filters: ['caseRef' => $caseId, 'classification' => 'deels_openbaar', '_limit' => 500],
		);

		$partlyPublic = 0;
		$waiting = [];
		foreach ($rows as $row) {
			if ((string)($row['caseRef'] ?? '') !== $caseId || (string)($row['classification'] ?? '') !== 'deels_openbaar') {
				continue;
			}

			$partlyPublic++;
			if ((string)($row['redactedDocumentRef'] ?? '') === '') {
				$waiting[] = (string)($row['documentRef'] ?? '');
			}
		}

		return ['partlyPublic' => $partlyPublic, 'waiting' => $waiting];
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
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-1
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-reviewer-changes-only-the-records-they-made-req-cra-003
	 */
	public function saveRedactionProposal(string $caseId, string $documentRef, array $proposal): array {
		$savedObject = $this->updateAssessment(caseId: $caseId, documentRef: $documentRef, fields: ['redactionProposal' => $proposal]);

		$this->logger->info(
			'WOO redaction proposal saved for document ' . $documentRef . ' in case ' . $caseId
			. ' (status: ' . ($proposal['status'] ?? 'unknown') . ')',
			['app' => Application::APP_ID],
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
	 */
	public function saveRedaction(string $caseId, string $documentRef, array $redaction, ?string $redactedDocumentRef = null): array {
		$fields = ['redaction' => $redaction];
		if ($redactedDocumentRef !== null) {
			$fields['redactedDocumentRef'] = $redactedDocumentRef;
		}

		return $this->updateAssessment(caseId: $caseId, documentRef: $documentRef, fields: $fields);
	}//end saveRedaction()

	/**
	 * Change fields of an EXISTING assessment, under the reviewer rule.
	 *
	 * @param string               $caseId      The case UUID.
	 * @param string               $documentRef The document UUID.
	 * @param array<string, mixed> $fields      The fields to set.
	 *
	 * @return array<string, mixed> The updated assessment record.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable or the document has not been assessed.
	 * @throws RefusedException When the caller may not change this assessment.
	 */
	private function updateAssessment(string $caseId, string $documentRef, array $fields): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$existing = $this->findAssessment(caseId: $caseId, documentRef: $documentRef);
		if ($existing === null) {
			throw new RuntimeException(
				'Document ' . $documentRef . ' must be assessed before requesting redaction assistance'
			);
		}

		// The redaction is part of the assessment, so the same rule holds as
		// for the verdict (REQ-CRA-003): its author, a supervisor or an
		// administrator.
		$this->reviewerAccess?->assertMayChange(
			caseId: $caseId,
			record: $existing,
			userId: (string)$this->userSession->getUser()?->getUID()
		);

		$saved = $this->patchObjectAsArray(
			objectService: $objectService,
			register: $this->settingsService->getConfigValue('register'),
			schema: $this->settingsService->getConfigValue('woo_assessment_schema'),
			id: (string)($existing['id'] ?? $existing['uuid'] ?? ''),
			changes: $fields,
		);

		return ($saved ?? array_merge($existing, $fields));
	}//end updateAssessment()
}//end class
