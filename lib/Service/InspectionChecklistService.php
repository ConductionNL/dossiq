<?php

/**
 * Dossiq Inspection Checklist Service
 *
 * Admin CRUD on `inspectionChecklistTemplate` objects (the one template
 * schema since inspection-checklists-onto-task 4.1). Runs are OpenRegister
 * tasks since 4.2, written and read by {@see Inspection\InspectionRunService}. Used by the checklist admin UI and the
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

}//end class
