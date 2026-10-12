<?php

/**
 * Dossiq assessed documents
 *
 * Every document of a case that is under review, each with its title,
 * whether it still waits for an assessment, and the assessment the caller
 * may read. The list a case page section shows; the outstanding list is the
 * part of it a dialog works through.
 *
 * Generic: nothing here names a procedure. Which case types assess documents
 * is configuration, and so is which fields of an assessment a screen shows.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Assessment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-every-document-with-its-assessment-req-das-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Assessment;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\WOODocumentAssessmentService;

/**
 * Lists every document under review on a case with its assessment.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-every-document-with-its-assessment-req-das-004
 */
class AssessedDocuments {

	use SearchesObjects;

	/**
	 * The most assessments read for one case, the limit the outstanding read uses.
	 *
	 * @var int
	 */
	private const LIMIT = 500;

	/**
	 * The fields of an assessment that only point back at the case and the document.
	 *
	 * @var list<string>
	 */
	private const REFERENCE_FIELDS = ['caseRef', 'documentRef'];

	/**
	 * Constructor.
	 *
	 * @param WOODocumentAssessmentService $assessments Knows which documents of a case are under review.
	 * @param OutstandingDocuments         $outstanding Names the documents that still wait, and titles a document.
	 * @param SettingsService              $settings    The register and the assessment schema.
	 */
	public function __construct(
		private readonly WOODocumentAssessmentService $assessments,
		private readonly OutstandingDocuments $outstanding,
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * Every document under review on a case, with its assessment.
	 *
	 * `outstanding` is the server's word, the same one the outstanding list
	 * gives. `assessment` is null for an outstanding document, and also for a
	 * document whose assessment the caller may not read: the assessments are
	 * read as the caller, so a reviewer's own work stays theirs.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{count: int, outstanding: int, documents: array<int, array<string, mixed>>} Each document as
	 *                                                                                        `{id, title, outstanding, assessment}`.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-every-document-with-its-assessment-req-das-004
	 */
	public function forCase(string $caseId): array {
		$waiting = array_fill_keys(array_column($this->outstanding->forCase(caseId: $caseId)['documents'], 'id'), true);
		$readable = $this->readableAssessments(caseId: $caseId);

		$documents = [];
		foreach ($this->assessments->getDocumentsUnderReview(caseId: $caseId) as $id) {
			$isWaiting = isset($waiting[$id]);
			$assessment = null;
			if ($isWaiting === false) {
				$assessment = ($readable[$id] ?? null);
			}

			$documents[] = [
				'id' => $id,
				'title' => $this->outstanding->titleOf(documentId: $id),
				'outstanding' => $isWaiting,
				'assessment' => $assessment,
			];
		}

		return ['count' => count($documents), 'outstanding' => count($waiting), 'documents' => $documents];
	}//end forCase()

	/**
	 * The assessments of a case the caller may read, by document.
	 *
	 * Read as the caller, never unscoped. The references back to the case and
	 * the document, and OpenRegister's own metadata, are left out: the row
	 * they sit in already says both.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, array<string, mixed>> The assessments, keyed by document uuid.
	 */
	private function readableAssessments(string $caseId): array {
		$objectService = $this->settings->getObjectService();
		$register = (string)$this->settings->getConfigValue('register');
		$schema = (string)$this->settings->getConfigValue('woo_assessment_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['caseRef' => $caseId, '_limit' => self::LIMIT],
		);

		$byDocument = [];
		foreach ($rows as $row) {
			$documentId = (string)($row['documentRef'] ?? '');
			if ($documentId === '' || (string)($row['caseRef'] ?? '') !== $caseId) {
				continue;
			}

			$byDocument[$documentId] = $this->ownFields(row: $row);
		}

		return $byDocument;
	}//end readableAssessments()

	/**
	 * An assessment's own fields: no OpenRegister metadata, no references back.
	 *
	 * @param array<string, mixed> $row The stored assessment.
	 *
	 * @return array<string, mixed> Its own fields, `id` among them.
	 */
	private function ownFields(array $row): array {
		$fields = [];
		foreach ($row as $key => $value) {
			if (str_starts_with((string)$key, '@') === false && in_array($key, self::REFERENCE_FIELDS, true) === false) {
				$fields[(string)$key] = $value;
			}
		}

		return $fields;
	}//end ownFields()
}//end class
