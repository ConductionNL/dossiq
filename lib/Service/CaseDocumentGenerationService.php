<?php

/**
 * Dossiq Case Document Generation Service
 *
 * Generates a letter from a library template and files it on the case.
 *
 * The rendering and the two writes belong to
 * {@see \OCA\Dossiq\Service\Actions\MergeTemplateHandler}: this service is the
 * seam that lets the Generate document button reach that handler while the
 * library cannot dispatch a `run-action` header action yet. It reads the
 * template from the library, the case from OpenRegister, and hands the handler
 * a config with NO `targetField` — which is exactly what the Flow node will
 * hand it once `actionsDispatcher.js` can run one.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 * @spec openspec/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Actions\ActionResult;
use OCA\Dossiq\Service\Actions\MergeTemplateHandler;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;

/**
 * Generate a document on a case from a library template.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class CaseDocumentGenerationService {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore $cases Reads the case the letter is about.
	 * @param TemplateLibraryService $templates The template library.
	 * @param SettingsService $settingsService Bridge to OpenRegister + config.
	 * @param MergeTemplateHandler $handler Renders and files the result.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseStatusStore $cases,
		private readonly TemplateLibraryService $templates,
		private readonly SettingsService $settingsService,
		private readonly MergeTemplateHandler $handler,
	) {
	}//end __construct()

	/**
	 * Render a library template over a case and file the result in its dossier.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $templateId The library template id.
	 *
	 * @return ActionResult Succeeded with the new informatieobject id, or a
	 *                      named failure having created nothing.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function generate(string $caseId, string $templateId): ActionResult {
		$template = $this->templates->loadTemplate($templateId);
		if ($template === null) {
			return new ActionResult(succeeded: false, error: 'template_not_found');
		}

		$body = (string)($template['body'] ?? '');
		if (trim($body) === '') {
			return new ActionResult(succeeded: false, error: 'template_has_no_body');
		}

		$case = $this->cases->loadCase(caseId: $caseId);
		if ($case === null) {
			return new ActionResult(succeeded: false, error: 'case_not_found');
		}

		// The handler takes the case id off the case itself, and a case read
		// through a projection that drops it would file the document nowhere.
		$case['id'] = ($case['id'] ?? $caseId);

		return $this->handler->handle(
			[
				'type' => 'mergeTemplate',
				'template' => $body,
				'templateName' => (string)($template['title'] ?? $templateId),
				'documentType' => $this->resolveDocumentType(
					name: (string)($template['documentType'] ?? '')
				),
			],
			$case,
			[]
		);
	}//end generate()

	/**
	 * Resolve a template's document type NAME to the catalogue row's id.
	 *
	 * `informatieobject.informatieobjecttype` holds a reference, and a template
	 * names its type the way a person would ("Ontvangstbevestiging"). When the
	 * catalogue holds no such row the name is passed through unchanged rather
	 * than blanked: the Documents tab renders an unresolved type as its raw
	 * value, so the column still reads Ontvangstbevestiging instead of empty.
	 *
	 * @param string $name The type name the template declares.
	 *
	 * @return string The catalogue row id, or the name, or empty.
	 *
	 * @spec openspec/specs/template-library/spec.md
	 */
	private function resolveDocumentType(string $name): string {
		if (trim($name) === '') {
			return '';
		}

		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'dossier_informatieobjecttype_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return $name;
		}

		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['description' => $name, '_limit' => 1],
		);

		$id = (string)(($rows[0]['id'] ?? ($rows[0]['uuid'] ?? '')));
		if ($id !== '') {
			return $id;
		}

		return $name;
	}//end resolveDocumentType()
}//end class
