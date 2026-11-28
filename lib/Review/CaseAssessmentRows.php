<?php

/**
 * Dossiq document review: the assessments of one case the caller may read.
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

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * Reads a case's document assessments from the configured assessment schema.
 *
 * One reading for DocumentVerdicts and ReviewSummary, which each build it
 * from their own settings service.
 *
 * @spec openspec/specs/document-assessment/spec.md#requirement-a-case-answers-its-document-verdicts-and-the-grounds-cited-req-cdv-001
 */
final class CaseAssessmentRows {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService The settings and OpenRegister access.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
	) {
	}//end __construct()

	/**
	 * The case's assessments the caller may read.
	 *
	 * Empty when OpenRegister, the register or the assessment schema is not available.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, mixed>> The assessments.
	 *
	 * @spec openspec/specs/document-assessment/spec.md#requirement-a-case-answers-its-document-verdicts-and-the-grounds-cited-req-cdv-001
	 */
	public function forCase(string $caseId): array {
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
	}//end forCase()

	/**
	 * Which documents of the case are assessed and with which verdict, whoever recorded the assessment.
	 *
	 * Read regardless of the caller (REQ-WSA-004), as the outstanding count reads them: an assessment
	 * another reviewer recorded is still an assessment, so every caller's summary counts it. Only the
	 * document reference and the verdict are answered; the grounds stay with forCase().
	 *
	 * @param string $caseId       The case UUID.
	 * @param string $verdictField The field that holds the verdict.
	 *
	 * @return array<int, array{documentRef: string, verdict: string}> One entry per assessment.
	 *
	 * @spec openspec/specs/document-assessment/spec.md#requirement-a-case-answers-its-document-verdicts-and-the-grounds-cited-req-cdv-001
	 */
	public function verdictsForCase(string $caseId, string $verdictField): array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('woo_assessment_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [];
		}

		$rows = $this->searchObjectsAsArraysUnscoped(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['caseRef' => $caseId, '_limit' => 1000],
		);

		return array_map(
			static fn (array $row): array => [
				'documentRef' => (string)($row['documentRef'] ?? ''),
				'verdict' => (string)($row[$verdictField] ?? ''),
			],
			$rows
		);
	}//end verdictsForCase()
}//end class
