<?php

/**
 * Dossiq Inspection Checklist Service
 *
 * Admin CRUD on `inspectionChecklistTemplate` objects (the one template
 * schema since inspection-checklists-onto-task 4.1) and per-case completion
 * via `inspectionResult` records (moving onto Task in 4.2). Used by the checklist admin UI and the
 * mobiel-inspectie consumer.
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
 * @spec openspec/specs/inspection-checklists/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Inspection\InspectionTemplateMapper;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Service for managing inspection checklists (admin CRUD + case completion).
 *
 * Distinct from the existing ChecklistService (which handles per-item
 * conformity completion during a mobile inspection run). This service
 * manages the template lifecycle: create/read/update/delete of
 * `inspectionChecklist` objects and submission of `inspectionResult` records.
 *
 * @spec openspec/specs/inspection-checklists/spec.md
 */
class InspectionChecklistService {

	use SearchesObjects;

	/**
	 * Maps the editor's flat view onto the template schema and back.
	 *
	 * @var InspectionTemplateMapper
	 */
	private InspectionTemplateMapper $mapper;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings bridge to OpenRegister
	 * @param LoggerInterface $logger Logger
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
		$this->mapper = new InspectionTemplateMapper();
	}//end __construct()

	/**
	 * Every checklist template, in the settings editor's flat view.
	 *
	 * Templates are `inspectionChecklistTemplate` objects since
	 * inspection-checklists-onto-task 4.1; the editor keeps its flat shape
	 * (`name`, `caseTypeRef`, `active`, `items`) through
	 * {@see InspectionTemplateMapper}.
	 *
	 * @param string|null $caseTypeRef Optional case type uuid to filter on.
	 *
	 * @return array<int, array<string, mixed>> The checklists.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function listChecklists(?string $caseTypeRef = null): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$params = ['_limit' => 100, '_order' => 'name'];
		if ($caseTypeRef !== null && $caseTypeRef !== '') {
			$params['caseType'] = $caseTypeRef;
		}

		try {
			$templates = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: InspectionTemplateMapper::SCHEMA,
				filters: $params
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Failed to list inspection checklists: ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return [];
		}

		return array_map(fn (array $template): array => $this->mapper->toFlat(template: $template), $templates);
	}//end listChecklists()

	/**
	 * Create a checklist template from the editor's flat payload.
	 *
	 * @param array<string, mixed> $data The flat checklist.
	 *
	 * @return array<string, mixed> The created checklist, flat.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function createChecklist(array $data): array {
		$template = $this->mapper->fromFlat(flat: $data);
		$template['version'] = (int)($data['version'] ?? 1);

		return $this->saveTemplate(template: $template, id: null);
	}//end createChecklist()

	/**
	 * Update a checklist template, moving its version up by one.
	 *
	 * @param string               $id   The template uuid.
	 * @param array<string, mixed> $data The flat checklist.
	 *
	 * @return array<string, mixed> The updated checklist, flat.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function updateChecklist(string $id, array $data): array {
		$template = $this->mapper->fromFlat(flat: $data);
		$template['version'] = ((int)($data['version'] ?? 1) + 1);

		return $this->saveTemplate(template: $template, id: $id);
	}//end updateChecklist()

	/**
	 * Delete a checklist template.
	 *
	 * @param string $id The template uuid.
	 *
	 * @return boolean True when deleted.
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/specs/inspection-checklists/spec.md#requirement-inspection-checklist-schema
	 */
	public function deleteChecklist(string $id): bool {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return false;
		}

		$register = $this->settingsService->getConfigValue('register');

		try {
			$objectService->deleteObject(
				uuid: $id,
				register: $register,
				schema: InspectionTemplateMapper::SCHEMA
			);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Failed to delete inspection checklist ' . $id . ': ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return false;
		}
	}//end deleteChecklist()

	/**
	 * Save a template and answer it flat.
	 *
	 * @param array<string, mixed> $template The template.
	 * @param string|null          $id       Its uuid on an update.
	 *
	 * @return array<string, mixed> The saved checklist, flat.
	 *
	 * @throws RuntimeException When OpenRegister is unavailable.
	 */
	private function saveTemplate(array $template, ?string $id): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$saved = $this->saveObjectAsArray(
			objectService: $objectService,
			register: $this->settingsService->getConfigValue('register'),
			schema: InspectionTemplateMapper::SCHEMA,
			object: $template,
			uuid: $id
		);

		return $this->mapper->toFlat(template: ($saved ?? $template));
	}//end saveTemplate()

	/**
	 * Submit an inspection result for a case.
	 *
	 * Validates that required-photo items have a photo reference when answered
	 * non-conformant. Saves the result and calculates the overall result.
	 *
	 * @param string $caseId UUID of the case
	 * @param string $checklistId UUID of the inspectionChecklist
	 * @param array<string, mixed> $resultData Answers and metadata
	 * @param string $completedBy User UID of the inspector
	 *
	 * @return array<string, mixed> Saved inspectionResult object
	 *
	 * @throws RuntimeException If validation fails or OpenRegister unavailable
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function submitResult(
		string $caseId,
		string $checklistId,
		array $resultData,
		string $completedBy,
	): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');

		// Validate required-photo items.
		$answers = $resultData['answers'] ?? [];
		$this->validatePhotoRequirements(answers: $answers, register: $register, objectService: $objectService);

		// Calculate overall result.
		$overallResult = $this->calculateOverallResult(answers: $answers);

		// SCHEMA PROPERTY NAMES, not `caseRef`/`checklistRef` (#799). The
		// `inspectionResult` schema declares `case` and `checklist` and lists
		// both as REQUIRED, so every submission this method ever made was
		// rejected by OpenRegister's validator — the probe on the issue got
		// `The required properties (case, checklist) are missing`, which is
		// also why the reporter could demonstrate the guard failing open
		// without ever landing a forged record. Read and write now agree with
		// the schema, so `getResultsForCase()` below finds what this wrote.
		$payload = [
			'case' => $caseId,
			'checklist' => $checklistId,
			'completedBy' => $completedBy,
			'completedAt' => date(format: 'c'),
			'answers' => $answers,
			'overallResult' => $overallResult,
			'remarks' => $resultData['remarks'] ?? '',
			'location' => $resultData['location'] ?? '',
		];

		$saved = $objectService->saveObject(
			register: $register,
			schema: 'inspectionResult',
			object: $payload
		);

		$this->logger->info(
			'Inspection result submitted for case ' . $caseId . ' (result=' . $overallResult . ')',
			['app' => Application::APP_ID]
		);

		if (is_array($saved) === true) {
			return $saved;
		} return [];
	}//end submitResult()

	/**
	 * Get all inspection results for a case.
	 *
	 * @param string $caseId UUID of the case
	 *
	 * @return array<int, array<string, mixed>> List of inspectionResult objects
	 *
	 * @spec openspec/specs/inspection-checklists/spec.md
	 */
	public function getResultsForCase(string $caseId): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: 'inspectionResult',
				filters: ['case' => $caseId, '_limit' => 50, '_order' => 'completedAt']
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Failed to get inspection results for case ' . $caseId . ': ' . $e->getMessage(),
				['app' => Application::APP_ID]
			);
			return [];
		}
	}//end getResultsForCase()

	/**
	 * Validate that non-conformant answers with fotoRequired have a photoRef.
	 *
	 * @param array<int, mixed> $answers Array of answer objects
	 * @param string $register Register slug
	 * @param object $objectService OpenRegister object service
	 *
	 * @return void
	 *
	 * @throws RuntimeException If a required photo is missing
	 */
	private function validatePhotoRequirements(
		array $answers,
		string $register,
		object $objectService,
	): void {
		foreach ($answers as $answer) {
			if (is_array($answer) === false) {
				continue;
			}

			$value = $answer['value'] ?? '';
			$photoRef = $answer['photoRef'] ?? '';
			$itemRef = $answer['itemRef'] ?? '';

			if ($value !== 'non_conform' || $photoRef !== '') {
				continue;
			}

			// Look up the checklistItem to see if fotoRequired=true.
			if ($itemRef === '') {
				continue;
			}

			$this->assertItemPhotoRequirement(
				objectService: $objectService,
				register: $register,
				itemRef: $itemRef
			);
		}//end foreach
	}//end validatePhotoRequirements()

	/**
	 * Raise when the referenced checklistItem demands a photo.
	 *
	 * A failed item lookup is tolerated — submission is allowed rather than
	 * blocked on an infrastructure error.
	 *
	 * @param object $objectService OpenRegister object service
	 * @param string $register Register slug
	 * @param mixed $itemRef Reference to the checklistItem
	 *
	 * @return void
	 *
	 * @throws RuntimeException If a required photo is missing
	 */
	private function assertItemPhotoRequirement(
		object $objectService,
		string $register,
		mixed $itemRef,
	): void {
		try {
			// The find() call returns an ObjectEntity whose data lives in
			// protected properties, so get_object_vars() from out here read
			// an EMPTY array and the photo requirement never fired. The
			// array bridge goes through jsonSerialize(), which exposes the
			// real fields.
			$item = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: 'checklistItem',
				id: (string)$itemRef
			);

			if ($item !== null && ($item['photoRequired'] ?? false) === true) {
				throw new RuntimeException(
					'Photo required for non-conformant checklist item ' . $itemRef
				);
			}
		} catch (RuntimeException $e) {
			throw $e;
		} catch (Throwable) {
			// Item lookup failed — allow submission rather than blocking.
		}//end try
	}//end assertItemPhotoRequirement()

	/**
	 * Calculate the overall result based on answer values.
	 *
	 * - All answers conform → 'conform'
	 * - Any answer niet_conform → 'non_conform'
	 * - Otherwise → 'partly_conform'
	 *
	 * @param array<int, mixed> $answers Array of answer objects
	 *
	 * @return string 'conform'|'partly_conform'|'non_conform'
	 */
	private function calculateOverallResult(array $answers): string {
		$hasNietConform = false;
		$hasConform = false;

		foreach ($answers as $answer) {
			if (is_array($answer) === false) {
				continue;
			}

			$value = $answer['value'] ?? '';
			if ($value === 'non_conform') {
				$hasNietConform = true;
			} elseif ($value === 'conform') {
				$hasConform = true;
			}
		}

		if ($hasNietConform === true && $hasConform === false) {
			return 'non_conform';
		}

		if ($hasNietConform === true) {
			return 'partly_conform';
		}

		return 'conform';
	}//end calculateOverallResult()
}//end class
