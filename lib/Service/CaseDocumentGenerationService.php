<?php

/**
 * Dossiq Case Document Generation Service
 *
 * Generates a letter from a library template and files it on the case.
 *
 * Filinq renders it. This service asks by dispatching Filinq's
 * DocumentGenerationRequestedEvent with requestingApp `dossiq` (the same
 * request the `filinq.generate-document` flow step makes), then files the file
 * Filinq stored in the case dossier through {@see GeneratedDocumentFiler}. It
 * files it itself rather than leaving that to
 * {@see \OCA\Dossiq\Listener\DocumentGeneratedListener}, because the button
 * answers with the informatieobject it made; the request says so in its
 * metadata and the listener stands aside.
 *
 * Before it asks, it refuses a template the case cannot fill, as the handler
 * it replaced did: a letter with a hole where the addressee should be is
 * worse than no letter, and nothing is created.
 *
 * Without Filinq nobody answers the event. That is a refusal naming Filinq,
 * never a success.
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

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Actions\ActionResult;
use OCA\Dossiq\Service\Flow\RetiredTemplateSyntax;
use OCA\Dossiq\Service\Flow\UnmappableStep;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Zaakdossier\GeneratedDocumentFiler;
use OCA\Filinq\Event\DocumentGenerationRequestedEvent;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IL10N;
use Throwable;

/**
 * Generate a document on a case from a library template.
 *
 * @spec openspec/specs/beschikking-generatie/spec.md
 */
class CaseDocumentGenerationService {
	use SearchesObjects;

	/**
	 * Filinq's request event, named by string: Filinq is optional at runtime.
	 *
	 * @var string
	 */
	public const REQUEST_EVENT = 'OCA\\Filinq\\Event\\DocumentGenerationRequestedEvent';

	/**
	 * Constructor.
	 *
	 * @param CaseStatusStore        $cases           Reads the case the letter is about.
	 * @param TemplateLibraryService $templates       The template library.
	 * @param SettingsService        $settingsService Bridge to OpenRegister + config.
	 * @param IEventDispatcher       $events          Asks Filinq for the document.
	 * @param GeneratedDocumentFiler $filer           Files the document in the dossier.
	 * @param RetiredTemplateSyntax  $syntax          Rewrites the library template for Filinq.
	 * @param IL10N                  $l10n            The refusal a person reads.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseStatusStore $cases,
		private readonly TemplateLibraryService $templates,
		private readonly SettingsService $settingsService,
		private readonly IEventDispatcher $events,
		private readonly GeneratedDocumentFiler $filer,
		private readonly RetiredTemplateSyntax $syntax,
		private readonly IL10N $l10n,
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
	 * @spec openspec/changes/flow-nodes-to-their-owners/specs/flow-nodes-to-their-owners/spec.md
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

		// A case read through a projection that drops its id would otherwise
		// file the document nowhere.
		$case['id'] = ($case['id'] ?? $caseId);

		$missing = $this->missingFields(template: $body, case: $case);
		if ($missing !== []) {
			return new ActionResult(succeeded: false, error: 'missing_template_field:' . $missing[0]);
		}

		$documentType = $this->resolveDocumentType(name: (string)($template['documentType'] ?? ''));
		if ($documentType === '') {
			return new ActionResult(succeeded: false, error: 'missing_document_type');
		}

		$name = trim((string)($template['title'] ?? ''));
		if ($name === '') {
			$name = $templateId;
		}

		try {
			$text = $this->syntax->forDocument(template: $body, what: 'template');
		} catch (UnmappableStep $e) {
			return new ActionResult(succeeded: false, error: 'template_not_supported: ' . $e->getMessage());
		}

		return $this->requestAndFile(caseId: $caseId, case: $case, text: $text, name: $name, documentType: $documentType);
	}//end generate()

	/**
	 * Ask Filinq for the document, then file what it made.
	 *
	 * @param string               $caseId       The case.
	 * @param array<string, mixed> $case         The case, as the render context.
	 * @param string               $text         The template, in Filinq's syntax.
	 * @param string               $name         The document's name.
	 * @param string               $documentType The document type it is filed under.
	 *
	 * @return ActionResult The outcome.
	 */
	private function requestAndFile(string $caseId, array $case, string $text, string $name, string $documentType): ActionResult {
		if (class_exists(self::REQUEST_EVENT) === false) {
			return $this->filinqRequired();
		}

		$metadata = [
			GeneratedDocumentFiler::TYPE => $documentType,
			'direction' => 'outgoing',
			'addressees' => 'case',
			GeneratedDocumentFiler::FILED_BY_CALLER => true,
		];

		$event = new DocumentGenerationRequestedEvent(
			request: [
				'template' => $text,
				'templateName' => $name,
				'filename' => $name,
				'format' => 'pdf',
				'storeFile' => true,
				'data' => $case,
				'object' => [
					'register' => $this->settingsService->getConfigValue(key: 'register'),
					'schema' => $this->settingsService->getConfigValue(key: 'case_schema'),
					'id' => $caseId,
				],
				'metadata' => $metadata,
			],
			requestingApp: Application::APP_ID
		);
		$this->events->dispatchTyped($event);

		$error = $event->getError();
		if ($error !== null && $error !== '') {
			return new ActionResult(succeeded: false, error: 'document_generation_failed: ' . $error);
		}

		$result = $event->getResult();
		if ($event->isHandled() === false || $result === null) {
			return $this->filinqRequired();
		}

		$fileId = (int)($result['fileId'] ?? 0);
		if ($fileId <= 0) {
			return new ActionResult(succeeded: false, error: 'document_generation_failed: Filinq stored no file');
		}

		try {
			$created = $this->filer->file(
				caseId: $caseId,
				fileId: $fileId,
				title: $name,
				mime: (string)($result['mime'] ?? 'application/pdf'),
				metadata: $metadata
			);
		} catch (Throwable $e) {
			return new ActionResult(succeeded: false, error: 'document_not_filed: ' . $e->getMessage());
		}

		return new ActionResult(
			succeeded: true,
			data: ['informatieobject' => (string)($created['id'] ?? ''), 'case' => $caseId]
		);
	}//end requestAndFile()

	/**
	 * The refusal when no Filinq answers.
	 *
	 * @return ActionResult The failure.
	 */
	private function filinqRequired(): ActionResult {
		return new ActionResult(
			succeeded: false,
			error: $this->l10n->t('Generating a document needs the Filinq app. Ask your administrator to install and enable Filinq.')
		);
	}//end filinqRequired()

	/**
	 * The `{{case.path}}` placeholders the case cannot answer.
	 *
	 * @param string               $template The library template.
	 * @param array<string, mixed> $case     The case.
	 *
	 * @return array<int, string> The unresolved paths, in order.
	 */
	private function missingFields(string $template, array $case): array {
		$matches = [];
		preg_match_all('/\{\{\s*case\.([a-zA-Z0-9_.]+)\s*\}\}/', $template, $matches);

		$missing = [];
		foreach ($matches[1] as $path) {
			$cursor = $case;
			foreach (explode('.', $path) as $segment) {
				if (is_array($cursor) === false || array_key_exists($segment, $cursor) === false) {
					$missing[] = 'case.' . $path;
					continue 2;
				}

				$cursor = $cursor[$segment];
			}
		}

		return array_values(array_unique($missing));
	}//end missingFields()

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
