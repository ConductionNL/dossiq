<?php

/**
 * Dossiq Assessment Records
 *
 * The stored assessments of a case's documents: finding one, reading those
 * that judged their document partly public, and changing fields of an
 * existing one under the reviewer rule.
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
 * @spec openspec/changes/method-decomposition/tasks.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Assessment;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\RequiresObjectService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\WooAssessmentLocation;
use OCP\IUserSession;
use RuntimeException;

/**
 * The stored assessments of a case: find, read partly public, and change an existing one.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class AssessmentRecords {

	use SearchesObjects;
	use RequiresObjectService;

	/**
	 * Reads which assessment rows judged a case's document partly public.
	 *
	 * @var PartlyPublicRows
	 */
	private readonly PartlyPublicRows $partlyPublicRows;

	/**
	 * Constructor.
	 *
	 * @param SettingsService         $settingsService Where OpenRegister, the register and the schema are configured.
	 * @param IUserSession            $userSession     Who is changing an assessment.
	 * @param CaseReviewerAccess|null $reviewerAccess  Who may change an assessment; null lets everyone.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly ?CaseReviewerAccess $reviewerAccess = null,
	) {
		$this->partlyPublicRows = new PartlyPublicRows();
	}//end __construct()

	/**
	 * The stored assessment of a (case, document) pair, or null when there is none.
	 *
	 * @param string $caseId      The case UUID.
	 * @param string $documentRef The document UUID.
	 *
	 * @return array<string, mixed>|null The assessment record, or null if not yet assessed.
	 *
	 * @spec openspec/changes/archive/2026-07-16-woo-llm-anonymisation/tasks.md#task-2-1
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function find(string $caseId, string $documentRef): ?array {
		$location = $this->assessmentLocation();
		if ($location === null) {
			return null;
		}

		return $this->firstAssessment(
			location: $location,
			filters: [
				'caseRef' => $caseId,
				'documentRef' => $documentRef,
				'_limit' => 1,
			],
		);
	}//end find()

	/**
	 * Where the assessments are stored, or null when OpenRegister or its configuration is missing.
	 *
	 * @return WooAssessmentLocation|null The location.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function assessmentLocation(): ?WooAssessmentLocation {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$assessmentSchema = $this->settingsService->getConfigValue('woo_assessment_schema');
		if (empty($register) === true || empty($assessmentSchema) === true) {
			return null;
		}

		return new WooAssessmentLocation(objectService: $objectService, register: $register, schema: $assessmentSchema);
	}//end assessmentLocation()

	/**
	 * The first assessment matching the filters, or null when none does.
	 *
	 * @param WooAssessmentLocation $location Where the assessments are stored.
	 * @param array<string, mixed>  $filters  The search filters.
	 *
	 * @return array<string, mixed>|null The assessment record.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function firstAssessment(WooAssessmentLocation $location, array $filters): ?array {
		$existing = $this->searchObjectsAsArrays(
			objectService: $location->objectService,
			register: $location->register,
			schema: $location->schema,
			filters: $filters,
		);

		if (count($existing) > 0) {
			return $existing[0];
		}

		return null;
	}//end firstAssessment()

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
	public function partlyPublic(string $caseId): array {
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

		return $this->partlyPublicRows->rowsOf(rows: $rows, caseId: $caseId);
	}//end partlyPublic()

	/**
	 * The partly public documents of a case that have no redacted copy yet, whoever can read them.
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
		$objectService = $this->requireObjectService();
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

		return $this->partlyPublicRows->tally(rows: $rows, caseId: $caseId);
	}//end partlyPublicWithoutCopy()

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
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-a-reviewer-changes-only-the-records-they-made-req-cra-003
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function update(string $caseId, string $documentRef, array $fields): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$existing = $this->find(caseId: $caseId, documentRef: $documentRef);
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
	}//end update()
}//end class
