<?php

/**
 * Dossiq outstanding documents
 *
 * The documents of a case that still wait for an assessment, each with the
 * title a handler knows it by. The assessment service answers ids only; a
 * dialog that lists ids is a list nobody can pick from.
 *
 * Generic: nothing here names a procedure. Which case types assess documents
 * is configuration (the header action on the case page).
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
 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-which-documents-still-wait-for-an-assessment-req-das-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Assessment;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Service\Zaakdossier\DocumentRecordStore;

/**
 * Names the documents of a case that still wait for an assessment.
 *
 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-which-documents-still-wait-for-an-assessment-req-das-001
 */
class OutstandingDocuments {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param WOODocumentAssessmentService $assessments Knows which documents have no assessment yet.
	 * @param DocumentRecordStore          $records     Reads a document record of the case file.
	 * @param SettingsService              $settings    The register and the schema of documents filed before the case file.
	 */
	public function __construct(
		private readonly WOODocumentAssessmentService $assessments,
		private readonly DocumentRecordStore $records,
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * The outstanding documents of a case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array{count: int, documents: array<int, array{id: string, title: string}>}
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-which-documents-still-wait-for-an-assessment-req-das-001
	 */
	public function forCase(string $caseId): array {
		$outstanding = $this->assessments->getOutstanding(caseId: $caseId);

		$documents = [];
		foreach ((array)($outstanding['documents'] ?? []) as $id) {
			$id = (string)$id;
			if ($id === '') {
				continue;
			}

			$documents[] = ['id' => $id, 'title' => $this->titleOf(documentId: $id)];
		}

		return ['count' => count($documents), 'documents' => $documents];
	}//end forCase()

	/**
	 * The title of one document, or '' when no record carries one.
	 *
	 * The case file's record first, then the schema documents were filed in
	 * before the case file existed. A document whose record is gone keeps its
	 * place in the list with an empty title: it is still outstanding. A
	 * register that cannot answer is not caught here, so the route says 503
	 * instead of a list of untitled documents.
	 *
	 * @param string $documentId The document uuid.
	 *
	 * @return string The title, the file name when there is no title, or ''.
	 *
	 * @spec openspec/changes/woo-case-screens-and-objections/specs/document-assessment/spec.md#requirement-a-case-answers-which-documents-still-wait-for-an-assessment-req-das-001
	 */
	public function titleOf(string $documentId): string {
		$row = $this->records->findRecord(recordId: $documentId);
		if ($row === null) {
			$row = $this->earlierDocument(documentId: $documentId);
		}

		foreach (['title', 'fileName'] as $key) {
			$value = trim((string)($row[$key] ?? ''));
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}//end titleOf()

	/**
	 * A document filed before the case file existed, or an empty row.
	 *
	 * @param string $documentId The document uuid.
	 *
	 * @return array<string, mixed> The row, [] when there is none.
	 */
	private function earlierDocument(string $documentId): array {
		$objectService = $this->settings->getObjectService();
		$register = (string)$this->settings->getConfigValue('register');
		$schema = (string)$this->settings->getConfigValue('document_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		return ($this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $documentId) ?? []);
	}//end earlierDocument()
}//end class
