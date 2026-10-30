<?php

/**
 * Dossiq document review: the verdicts on a case's documents and the grounds they cite.
 *
 * @category Review
 * @package  OCA\Dossiq\Review
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/document-assessment/spec.md#requirement-a-case-answers-its-document-verdicts-and-the-grounds-cited-req-cdv-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review;

use OCA\Dossiq\Service\Assessment\OutstandingDocuments as DocumentTitles;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use OCP\IL10N;
use Throwable;

/**
 * Counts the verdicts on a case's documents and lists every ground they cite.
 *
 * The assessment records live in the configured assessment schema; which of
 * their fields holds the verdict and the cited grounds, and which verdict
 * marks a document as partly withheld, is configuration.
 *
 * @spec openspec/specs/document-assessment/spec.md#requirement-a-case-answers-its-document-verdicts-and-the-grounds-cited-req-cdv-001
 */
class DocumentVerdicts {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService              $settingsService The settings and OpenRegister access.
	 * @param WOODocumentAssessmentService $assessments     The documents of a case under review.
	 * @param DocumentTitles               $titles          The title of a document.
	 * @param WooRefusalGrounds            $grounds         The label of a cited ground.
	 * @param IL10N                        $l10n            The translations.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly WOODocumentAssessmentService $assessments,
		private readonly DocumentTitles $titles,
		private readonly WooRefusalGrounds $grounds,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The verdicts of one case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed> `documents`, `assessed`, `remaining`, `counts` per verdict and `grounds`.
	 *
	 * @spec openspec/specs/document-assessment/spec.md#requirement-a-case-answers-its-document-verdicts-and-the-grounds-cited-req-cdv-001
	 */
	public function forCase(string $caseId): array {
		$documents = $this->assessments->getDocumentsUnderReview(caseId: $caseId);
		$verdictField = $this->settingsService->getConfigValue('document_verdict_field', 'classification');
		$groundsField = $this->settingsService->getConfigValue('document_grounds_field', 'weigeringsgronden');
		$partial = $this->settingsService->getConfigValue('document_partial_verdict', 'deels_openbaar');

		$counts = [];
		$cited = [];
		$assessed = 0;
		foreach ($this->assessmentRows(caseId: $caseId) as $row) {
			$documentRef = (string)($row['documentRef'] ?? '');
			$verdict = (string)($row[$verdictField] ?? '');
			if ($verdict === '' || in_array($documentRef, $documents, true) === false) {
				continue;
			}

			$assessed++;
			$counts[$verdict] = (($counts[$verdict] ?? 0) + 1);
			foreach ((array)($row[$groundsField] ?? []) as $code) {
				$code = trim((string)$code);
				if ($code !== '') {
					$cited[$code][] = ['documentRef' => $documentRef, 'partial' => ($verdict === $partial)];
				}
			}
		}

		return [
			'documents' => count($documents),
			'assessed' => $assessed,
			'remaining' => max(0, (count($documents) - $assessed)),
			'counts' => $counts,
			'grounds' => $this->groundRows(cited: $cited),
		];
	}//end forCase()

	/**
	 * Each document under review with its verdict, `open` while it has none.
	 *
	 * Reads only the assessments the caller may read, as {@see self::forCase()}.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, string> The verdict per document id.
	 *
	 * @spec openspec/specs/files-review-row-verdict/spec.md#requirement-each-document-row-carries-its-verdict
	 */
	public function byDocument(string $caseId): array {
		$documents = $this->assessments->getDocumentsUnderReview(caseId: $caseId);
		$verdictField = $this->settingsService->getConfigValue('document_verdict_field', 'classification');

		$verdicts = array_fill_keys(array_map('strval', $documents), 'open');
		foreach ($this->assessmentRows(caseId: $caseId) as $row) {
			$documentRef = (string)($row['documentRef'] ?? '');
			$verdict = (string)($row[$verdictField] ?? '');
			if ($verdict !== '' && isset($verdicts[$documentRef]) === true) {
				$verdicts[$documentRef] = $verdict;
			}
		}

		return $verdicts;
	}//end byDocument()

	/**
	 * Every cited ground in code order, with its label and the documents citing it.
	 *
	 * @param array<string, list<array{documentRef: string, partial: bool}>> $cited The citations per code.
	 *
	 * @return list<array{code: string, label: string, documentList: string}> The grounds.
	 */
	private function groundRows(array $cited): array {
		uksort($cited, 'strnatcmp');
		$rows = [];
		foreach ($cited as $code => $citations) {
			$names = [];
			foreach ($citations as $citation) {
				$title = $this->titles->titleOf(documentId: $citation['documentRef']);
				if ($citation['partial'] === true) {
					$title = $this->l10n->t('%s (partly)', [$title]);
				}

				$names[] = $title;
			}

			$rows[] = [
				'code' => (string)$code,
				'label' => $this->labelOf(code: (string)$code),
				'documentList' => implode(', ', $names),
			];
		}

		return $rows;
	}//end groundRows()

	/**
	 * A ground's label, or '' when the list of grounds cannot be read or does not know the code.
	 *
	 * @param string $code The ground's code.
	 *
	 * @return string The label.
	 */
	private function labelOf(string $code): string {
		try {
			return (string)($this->grounds->byCode(code: $code)['label'] ?? '');
		} catch (Throwable) {
			return '';
		}
	}//end labelOf()

	/**
	 * The case's assessments the caller may read.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, mixed>> The assessments.
	 */
	private function assessmentRows(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('woo_assessment_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['caseRef' => $caseId, '_limit' => 1000],
		);
	}//end assessmentRows()
}//end class
