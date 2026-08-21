<?php

/**
 * Procest WOO Document Assessment Service
 *
 * Service for per-document WOO disclosure assessments. Manages wooAssessment
 * records (openbaar / deels openbaar / niet openbaar) with mandatory
 * weigeringsgrond validation per WOO Art. 5.1/5.2. Guards stage advancement
 * until every collected document has been assessed.
 *
 * @category Service
 * @package  OCA\Procest\Service
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

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
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
	 * Valid classification values.
	 */
	private const VALID_CLASSIFICATIONS = [
		'openbaar',
		'deels_openbaar',
		'niet_openbaar',
	];

	/**
	 * Classifications that require at least one weigeringsgrond.
	 */
	private const REQUIRES_WEIGERINGSGROND = [
		'niet_openbaar',
		'deels_openbaar',
	];

	/**
	 * Valid WOO Art. 5.1/5.2 weigeringsgrond codes.
	 */
	private const VALID_WEIGERINGSGRONDEN = [
		'5.1.1',
		'5.1.2',
		'5.1.3',
		'5.1.4',
		'5.1.5',
		'5.2.1',
		'5.2.2',
		'5.2.3',
		'5.2.4',
		'5.2.5',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service
	 * @param IUserSession $userSession Current user session
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
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

		$userId = 'system';
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$userId = $user->getUID();
		}

		$saved = [];
		$errors = [];

		foreach ($assessments as $assessment) {
			$validationErrors = $this->validate(assessment: $assessment);
			if (empty($validationErrors) === false) {
				$errors[] = [
					'documentRef' => $assessment['documentRef'] ?? 'unknown',
					'errors' => $validationErrors,
				];
				continue;
			}

			$assessment['caseRef'] = $caseId;
			$assessment['assessedBy'] = $userId;
			$assessment['assessedAt'] = date('Y-m-d\TH:i:s');

			// Find existing assessment for this document in this case.
			$existing = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $assessmentSchema,
				filters: [
					'caseRef' => $caseId,
					'documentRef' => $assessment['documentRef'],
					'_limit' => 1,
				],
			);

			if (count($existing) > 0) {
				$existingId = $existing[0]['id'] ?? $existing[0]['uuid'] ?? null;
				$saved[] = $objectService->saveObject(
					object: $assessment,
					register: $register,
					schema: $assessmentSchema,
					uuid: (string)$existingId,
				);
				continue;
			}

			$saved[] = $objectService->saveObject(
				object: $assessment,
				register: $register,
				schema: $assessmentSchema,
			);
		}//end foreach

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
	 * Validate a single assessment payload.
	 *
	 * @param array<string, mixed> $assessment The assessment to validate
	 *
	 * @return array<string, string> Validation errors keyed by field name; empty if valid
	 *
	 * @spec openspec/changes/woo-case-type/tasks.md#task-5
	 */
	public function validate(array $assessment): array {
		$errors = [];

		if (empty($assessment['documentRef']) === true) {
			$errors['documentRef'] = 'documentRef is required';
		}

		$classification = $assessment['classification'] ?? null;
		if (empty($classification) === true) {
			$errors['classification'] = 'classification is required';
		} elseif (in_array($classification, self::VALID_CLASSIFICATIONS, true) === false) {
			$errors['classification'] = 'Invalid classification. Must be one of: '
				. implode(', ', self::VALID_CLASSIFICATIONS);
		} elseif (in_array($classification, self::REQUIRES_WEIGERINGSGROND, true) === true) {
			$grounds = $assessment['weigeringsgronden'] ?? [];
			if (empty($grounds) === true) {
				$errors['weigeringsgronden'] = 'At least one weigeringsgrond is required for '
					. $classification . ' (WOO Art. 5.1/5.2)';
				return $errors;
			}

			foreach ($grounds as $code) {
				if (in_array($code, self::VALID_WEIGERINGSGRONDEN, true) === false) {
					$errors['weigeringsgronden'] = 'Invalid weigeringsgrond code: ' . $code;
					break;
				}
			}
		}

		return $errors;
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

		// Collect all assessed document IDs.
		$assessedDocIds = $this->collectAssessedDocumentIds(
			objectService: $objectService,
			register: $register,
			assessmentSchema: $assessmentSchema,
			caseId: $caseId,
		);

		$outstanding = array_keys(array_diff_key($allDocs, $assessedDocIds));

		return [
			'count' => count($outstanding),
			'documents' => $outstanding,
		];
	}//end getOutstanding()

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
	 * Collect the identifiers of every document of a case that already carries an assessment.
	 *
	 * @param object $objectService OpenRegister object service
	 * @param mixed $register Configured register identifier
	 * @param mixed $assessmentSchema Configured assessment schema identifier
	 * @param string $caseId The case UUID
	 *
	 * @return array<string, bool> Assessed document identifiers as keys, empty when the schema is not configured
	 */
	private function collectAssessedDocumentIds(object $objectService, mixed $register, mixed $assessmentSchema, string $caseId): array {
		if (empty($assessmentSchema) === true) {
			return [];
		}

		$assessed = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $assessmentSchema,
			filters: ['caseRef' => $caseId, '_limit' => 500],
		);

		// No is_array() guard: $assessed is already typed as an array.
		$assessedDocIds = [];
		foreach ($assessed as $item) {
			$docRef = $item['documentRef'] ?? null;
			if ($docRef !== null) {
				$assessedDocIds[$docRef] = true;
			}
		}

		return $assessedDocIds;
	}//end collectAssessedDocumentIds()

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
	 *
	 * @spec openspec/changes/woo-llm-anonymisation/tasks.md#task-2-1
	 */
	public function saveRedactionProposal(string $caseId, string $documentRef, array $proposal): array {
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

		$register = $this->settingsService->getConfigValue('register');
		$assessmentSchema = $this->settingsService->getConfigValue('woo_assessment_schema');
		$existingId = $existing['id'] ?? $existing['uuid'] ?? null;

		$updated = $existing;
		$updated['redactionProposal'] = $proposal;

		$savedObject = $objectService->saveObject(
			object: $updated,
			register: $register,
			schema: $assessmentSchema,
			uuid: (string)$existingId,
		);

		$this->logger->info(
			'WOO redaction proposal saved for document ' . $documentRef . ' in case ' . $caseId
			. ' (status: ' . ($proposal['status'] ?? 'unknown') . ')',
			['app' => Application::APP_ID],
		);

		return $savedObject;
	}//end saveRedactionProposal()
}//end class
