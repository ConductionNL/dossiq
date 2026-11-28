<?php

/**
 * Which assessment rows judged a case's document partly public.
 *
 * Split out of {@see \OCA\Dossiq\Service\WOODocumentAssessmentService}, which
 * keeps the reads. This class reads no store. A search can answer more than
 * it was asked for, so every row is checked again for its case and its
 * classification before it counts.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Assessment
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
 * @spec openspec/specs/status-transition-engine/spec.md#requirement-a-move-may-require-every-partly-public-document-redacted
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Assessment;

/**
 * The partly public rows of a case, and those still waiting for a redacted copy.
 *
 * @spec openspec/specs/status-transition-engine/spec.md#requirement-a-move-may-require-every-partly-public-document-redacted
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class PartlyPublicRows {

	/**
	 * The classification of a document that is disclosed in part.
	 *
	 * @var string
	 */
	public const PARTLY_PUBLIC = 'deels_openbaar';

	/**
	 * The rows of the case that judged their document partly public, as a list.
	 *
	 * @param array<int, array<string, mixed>> $rows   The assessments read.
	 * @param string                           $caseId The case UUID.
	 *
	 * @return array<int, array<string, mixed>> The partly public assessments.
	 *
	 * @spec openspec/specs/document-redaction/spec.md#requirement-the-redaction-panel-shows-what-filinq-and-hermiq-detected-req-drd-001
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function rowsOf(array $rows, string $caseId): array {
		return array_values(
			array_filter(
				$rows,
				fn (array $row): bool => $this->isPartlyPublicOf(row: $row, caseId: $caseId)
			)
		);
	}//end rowsOf()

	/**
	 * How many of the rows are the case's partly public documents, and which of them have no redacted copy.
	 *
	 * @param array<int, array<string, mixed>> $rows   The assessments read.
	 * @param string                           $caseId The case UUID.
	 *
	 * @return array{partlyPublic: int, waiting: list<string>} The count and the documents still waiting.
	 *
	 * @spec openspec/specs/status-transition-engine/spec.md#requirement-a-move-may-require-every-partly-public-document-redacted
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function tally(array $rows, string $caseId): array {
		$partlyPublic = 0;
		$waiting = [];
		foreach ($rows as $row) {
			if ($this->isPartlyPublicOf(row: $row, caseId: $caseId) === false) {
				continue;
			}

			$partlyPublic++;
			if ((string)($row['redactedDocumentRef'] ?? '') === '') {
				$waiting[] = (string)($row['documentRef'] ?? '');
			}
		}

		return ['partlyPublic' => $partlyPublic, 'waiting' => $waiting];
	}//end tally()

	/**
	 * Whether an assessment is on the case and judged its document partly public.
	 *
	 * @param array<string, mixed> $row    The assessment.
	 * @param string               $caseId The case UUID.
	 *
	 * @return bool True for a partly public assessment of the case.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function isPartlyPublicOf(array $row, string $caseId): bool {
		return (string)($row['caseRef'] ?? '') === $caseId
			&& (string)($row['classification'] ?? '') === self::PARTLY_PUBLIC;
	}//end isPartlyPublicOf()
}//end class
