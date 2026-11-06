<?php

/**
 * Dossiq outstanding documents.
 *
 * Which documents of a case still hold up its decision: every document on
 * the case, less the ones set aside before review, less the ones that carry
 * a verdict. With relevance recorded, an out-of-scope document needs no
 * verdict and an unmarked one is outstanding whatever it carries.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-5
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use OCA\Dossiq\Service\Drafts\CaseDocumentDrafts;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Woo\WooCaseDocuments;

/**
 * Reads the documents of a case that still need a verdict.
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-5
 */
class OutstandingDocuments {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService        $settingsService The register and the document, assessment and exclusion schemas.
	 * @param WooCaseDocuments|null  $caseDocuments   Where a case's documents are: its informatieobjecten.
	 * @param DocumentRelevance|null $reviews         Each document's relevance; null keeps every document needing a verdict.
	 * @param CaseDocumentDrafts|null $drafts         The document types the case type drafts itself; null reviews every document.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ?WooCaseDocuments $caseDocuments = null,
		private readonly ?DocumentRelevance $reviews = null,
		private readonly ?CaseDocumentDrafts $drafts = null,
	) {
	}//end __construct()

	/**
	 * Get documents without a completed assessment for a case.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return array<string, mixed> Array with 'count' and 'documents' list of unassessed doc IDs
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-5
	 */
	public function forCase(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return ['count' => 0, 'documents' => []];
		}

		$register = $this->settingsService->getConfigValue('register');
		$docSchema = $this->settingsService->getConfigValue('document_schema');
		$assessmentSchema = $this->settingsService->getConfigValue('woo_assessment_schema');

		if (empty($register) === true) {
			return ['count' => 0, 'documents' => []];
		}

		// Collect all documents for this case.
		$allDocs = $this->collectCaseDocumentIds(
			objectService: $objectService,
			register: $register,
			docSchema: $docSchema,
			caseId: $caseId,
		);

		if (empty($allDocs) === true) {
			return ['count' => 0, 'documents' => []];
		}

		$assessments = $this->assessmentRows(
			objectService: $objectService,
			register: $register,
			assessmentSchema: $assessmentSchema,
			caseId: $caseId,
		);
		$assessedDocIds = $this->refsOf(assessments: $assessments, field: 'documentRef');

		// A document set aside before review is not waiting for assessment
		// (woo-request-corpus-collection, REQ-WRC-003), and the redacted copy
		// of a partly public document is part of that document's assessment.
		$excludedDocIds = $this->collectExcludedDocumentIds(objectService: $objectService, register: $register, caseId: $caseId)
			+ $this->refsOf(assessments: $assessments, field: 'redactedDocumentRef');

		$outstanding = $this->outstandingOf(caseId: $caseId, allDocs: array_diff_key($allDocs, $excludedDocIds), assessedDocIds: $assessedDocIds);

		return [
			'count' => count($outstanding),
			'documents' => $outstanding,
		];
	}//end forCase()

	/**
	 * Every document of a case that is under review: on the case and not set aside.
	 *
	 * The same two reads {@see self::forCase()} starts from, without the
	 * assessments, for a caller that lists every document and not only the
	 * outstanding ones.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return list<string> The document identifiers.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-every-document-with-its-assessment-req-das-004
	 */
	public function underReview(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		if ($objectService === null || empty($register) === true) {
			return [];
		}

		$allDocs = $this->collectCaseDocumentIds(
			objectService: $objectService,
			register: $register,
			docSchema: $this->settingsService->getConfigValue('document_schema'),
			caseId: $caseId,
		);
		if ($allDocs === []) {
			return [];
		}

		$excluded = $this->collectExcludedDocumentIds(objectService: $objectService, register: $register, caseId: $caseId)
			+ $this->refsOf(
				assessments: $this->assessmentRows(
					objectService: $objectService,
					register: $register,
					assessmentSchema: $this->settingsService->getConfigValue('woo_assessment_schema'),
					caseId: $caseId,
				),
				field: 'redactedDocumentRef'
			);

		return array_map('strval', array_keys(array_diff_key($allDocs, $excluded)));
	}//end underReview()

	/**
	 * The documents that still hold up the decision.
	 *
	 * With relevance recorded (woo-review-triage), an out-of-scope document
	 * needs no verdict, an in-scope one needs a verdict, and an unmarked one
	 * is outstanding whatever it carries: nobody has said yet whether it is
	 * about the request. Without the review schema every document needs a
	 * verdict, as before, which is the stricter reading.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<string, bool> $allDocs The case's documents as keys.
	 * @param array<string, bool> $assessedDocIds The assessed documents as keys.
	 *
	 * @return list<string> The outstanding documents.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-relevance-is-marked-apart-from-the-verdict-and-reported-req-wrt-001
	 */
	private function outstandingOf(string $caseId, array $allDocs, array $assessedDocIds): array {
		if ($this->reviews === null) {
			return array_map('strval', array_keys(array_diff_key($allDocs, $assessedDocIds)));
		}

		return $this->reviews->outstanding(caseId: $caseId, documentIds: array_keys($allDocs), assessed: $assessedDocIds);
	}//end outstandingOf()

	/**
	 * Collect the identifiers of every document attached to a case.
	 *
	 * @param object $objectService OpenRegister object service
	 * @param mixed $register Configured register identifier
	 * @param mixed $docSchema Configured document schema identifier
	 * @param string $caseId The case UUID
	 *
	 * @return array<string, bool> Document identifiers as keys, empty when the schema is not configured
	 */
	private function collectCaseDocumentIds(object $objectService, mixed $register, mixed $docSchema, string $caseId): array {
		// Where the case upload writes documents: informatieobjecten joined to
		// the case (found by the e2e Woo journey, portaliq#1001).
		if ($this->caseDocuments !== null) {
			return array_fill_keys($this->withoutDraftedDocuments(caseId: $caseId, ids: $this->caseDocuments->idsFor(caseId: $caseId)), true);
		}

		if (empty($docSchema) === true) {
			return [];
		}

		$docs = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $docSchema,
			filters: ['case' => $caseId, '_limit' => 500],
		);

		// No is_array() guard: $docs is already typed as an array.
		$allDocs = [];
		foreach ($docs as $doc) {
			$docId = $doc['id'] ?? $doc['uuid'] ?? null;
			if ($docId !== null) {
				$allDocs[$docId] = true;
			}
		}

		return $allDocs;
	}//end collectCaseDocumentIds()

	/**
	 * The case's documents without the ones the case drafts itself.
	 *
	 * A letter drafted from the case type's template (`documentDrafts`, the
	 * decision letter) is the case's own answer, not a document to assess, so
	 * a document of a drafted type is left out of the review.
	 *
	 * @param string       $caseId The case UUID.
	 * @param list<string> $ids    The case's document identifiers.
	 *
	 * @return list<string> The identifiers under review.
	 */
	private function withoutDraftedDocuments(string $caseId, array $ids): array {
		if ($this->drafts === null || $this->caseDocuments === null || $ids === []) {
			return $ids;
		}

		$drafted = $this->drafts->documentTypesForCase(caseId: $caseId);
		if ($drafted === []) {
			return $ids;
		}

		return array_values(
			array_filter(
				$ids,
				fn (string $id): bool => in_array((string)($this->caseDocuments->meta(documentId: $id)['informatieobjecttype'] ?? ''), $drafted, true) === false
			)
		);
	}//end withoutDraftedDocuments()

	/**
	 * Every assessment of a case.
	 *
	 * @param object $objectService OpenRegister object service
	 * @param mixed $register Configured register identifier
	 * @param mixed $assessmentSchema Configured assessment schema identifier
	 * @param string $caseId The case UUID
	 *
	 * @return array<int, array<string, mixed>> The assessments, empty when the schema is not configured
	 */
	private function assessmentRows(object $objectService, mixed $register, mixed $assessmentSchema, string $caseId): array {
		if (empty($assessmentSchema) === true) {
			return [];
		}

		// Read regardless of the caller (REQ-WSA-004): a document assessed by a
		// reviewer whose assessment this caller may not read is still assessed,
		// not outstanding. Only document references are used; nothing of the
		// assessment itself is answered.
		return $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $assessmentSchema,
			filters: ['caseRef' => $caseId, '_limit' => 500],
		);
	}//end assessmentRows()

	/**
	 * The document references one field of the assessments names.
	 *
	 * @param array<int, array<string, mixed>> $assessments The assessments.
	 * @param string                           $field       `documentRef`, or `redactedDocumentRef` for the redacted copies.
	 *
	 * @return array<string, bool> The references as keys.
	 */
	private function refsOf(array $assessments, string $field): array {
		$refs = [];
		foreach ($assessments as $item) {
			$ref = (string)($item[$field] ?? '');
			if ($ref !== '') {
				$refs[$ref] = true;
			}
		}

		return $refs;
	}//end refsOf()

	/**
	 * Collect the identifiers of every document of a case that was set aside before review.
	 *
	 * @param object $objectService OpenRegister object service
	 * @param mixed  $register      Configured register identifier
	 * @param string $caseId        The case UUID
	 *
	 * @return array<string, bool> Excluded document identifiers as keys, empty when the schema is not configured
	 */
	private function collectExcludedDocumentIds(object $objectService, mixed $register, string $caseId): array {
		$exclusionSchema = $this->settingsService->getConfigValue('woo_exclusion_schema');
		if (empty($exclusionSchema) === true) {
			return [];
		}

		// An exclusion is private to the people on its case, and what holds up a decision is a rule of
		// the case: it is read whoever asks, and kept only when it names this case.
		$excluded = [];
		$rows = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $exclusionSchema,
			filters: ['case' => $caseId, '_limit' => 1000],
		);
		foreach ($rows as $row) {
			$docRef = (string)($row['documentRef'] ?? '');
			if ($docRef !== '' && (string)($row['case'] ?? '') === $caseId) {
				$excluded[$docRef] = true;
			}
		}

		return $excluded;
	}//end collectExcludedDocumentIds()
}//end class
