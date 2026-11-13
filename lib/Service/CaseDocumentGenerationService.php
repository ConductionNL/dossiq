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

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Service\Actions\ActionResult;
use OCA\Dossiq\Service\Flow\UnmappableStep;
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
	 * @param DocumentTemplating $templating The template library, the filer for generated documents and the retired template syntax.
	 * @param SettingsService        $settingsService Bridge to OpenRegister + config.
	 * @param IEventDispatcher       $events          Asks Filinq for the document.
	 * @param IL10N                  $l10n            The refusal a person reads.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseStatusStore $cases,
		private readonly DocumentTemplating $templating,
		private readonly SettingsService $settingsService,
		private readonly IEventDispatcher $events,
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
	 * @spec openspec/specs/flow-nodes-to-their-owners/spec.md
	 */
	public function generate(string $caseId, string $templateId): ActionResult {
		$template = $this->templating->templates->loadTemplate($templateId);
		$refusal = $this->templateRefusal(template: $template);
		if ($refusal !== null) {
			return $refusal;
		}

		$case = $this->cases->loadCase(caseId: $caseId);
		if ($case === null) {
			return new ActionResult(succeeded: false, error: 'case_not_found');
		}

		$run = new GeneratedDocumentRun(caseId: $caseId, case: $case);
		$run->templateId = $templateId;
		$refusal = $this->prepareDocument(run: $run, template: $template);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->requestAndFile(run: $run);
	}//end generate()

	/**
	 * Why a template cannot be generated from, or null when it can.
	 *
	 * @param array<string, mixed>|null $template The library template, or null when unknown.
	 *
	 * @return ActionResult|null The refusal.
	 */
	private function templateRefusal(?array $template): ?ActionResult {
		if ($template === null) {
			return new ActionResult(succeeded: false, error: 'template_not_found');
		}

		if (trim((string)($template['body'] ?? '')) === '') {
			return new ActionResult(succeeded: false, error: 'template_has_no_body');
		}

		return null;
	}//end templateRefusal()

	/**
	 * Check the template against the case and set the document's type, name
	 * and text; the refusal when it cannot be made, null when it can.
	 *
	 * @param GeneratedDocumentRun $run      The document being made.
	 * @param array<string, mixed> $template The library template.
	 *
	 * @return ActionResult|null The refusal.
	 */
	private function prepareDocument(GeneratedDocumentRun $run, array $template): ?ActionResult {
		$refusal = $this->fieldRefusal(run: $run, template: $template);
		if ($refusal !== null) {
			return $refusal;
		}

		$run->name = $this->documentName(run: $run, template: $template);

		return $this->convertTemplate(run: $run, body: (string)($template['body'] ?? ''));
	}//end prepareDocument()

	/**
	 * A placeholder the case cannot answer, or a template without a document
	 * type, refuses; otherwise the type is set and null returned.
	 *
	 * @param GeneratedDocumentRun $run      The document being made.
	 * @param array<string, mixed> $template The library template.
	 *
	 * @return ActionResult|null The refusal.
	 */
	private function fieldRefusal(GeneratedDocumentRun $run, array $template): ?ActionResult {
		$missing = $this->missingFields(template: (string)($template['body'] ?? ''), case: $run->case);
		if ($missing !== []) {
			return new ActionResult(succeeded: false, error: 'missing_template_field:' . $missing[0]);
		}

		$run->documentType = trim((string)($template['documentType'] ?? ''));
		if ($run->documentType === '') {
			return new ActionResult(succeeded: false, error: 'missing_document_type');
		}

		return null;
	}//end fieldRefusal()

	/**
	 * Put the template into Filinq's syntax, or refuse one it cannot carry.
	 *
	 * @param GeneratedDocumentRun $run  The document being made.
	 * @param string               $body The template body.
	 *
	 * @return ActionResult|null The refusal.
	 */
	private function convertTemplate(GeneratedDocumentRun $run, string $body): ?ActionResult {
		try {
			$run->text = $this->templating->syntax->forDocument(template: $body, what: 'template');
		} catch (UnmappableStep $e) {
			return new ActionResult(succeeded: false, error: 'template_not_supported: ' . $e->getMessage());
		}

		return null;
	}//end convertTemplate()

	/**
	 * The document's name: the template's title, or its id when it has none.
	 *
	 * @param GeneratedDocumentRun $run      The document being made.
	 * @param array<string, mixed> $template The library template.
	 *
	 * @return string
	 */
	private function documentName(GeneratedDocumentRun $run, array $template): string {
		$name = trim((string)($template['title'] ?? ''));
		if ($name === '') {
			return $run->templateId;
		}

		return $name;
	}//end documentName()

	/**
	 * Ask Filinq for the document, then file what it made.
	 *
	 * @param GeneratedDocumentRun $run The document being made.
	 *
	 * @return ActionResult The outcome.
	 */
	private function requestAndFile(GeneratedDocumentRun $run): ActionResult {
		if (class_exists(self::REQUEST_EVENT) === false) {
			return $this->filinqRequired();
		}

		$run->metadata = [
			GeneratedDocumentFiler::TYPE => $run->documentType,
			'direction' => 'outgoing',
			'addressees' => 'case',
			GeneratedDocumentFiler::FILED_BY_CALLER => true,
		];
		$event = $this->generationRequest(run: $run);
		$this->events->dispatchTyped($event);

		$refusal = $this->generationRefusal(event: $event);
		if ($refusal !== null) {
			return $refusal;
		}

		return $this->fileGenerated(run: $run, result: (array)$event->getResult());
	}//end requestAndFile()

	/**
	 * The generation request Filinq is asked to answer.
	 *
	 * @param GeneratedDocumentRun $run The document being made.
	 *
	 * @return DocumentGenerationRequestedEvent
	 */
	private function generationRequest(GeneratedDocumentRun $run): DocumentGenerationRequestedEvent {
		return new DocumentGenerationRequestedEvent(
			request: [
				'template' => $run->text,
				'templateName' => $run->name,
				'filename' => $run->name,
				'format' => 'pdf',
				'storeFile' => true,
				'data' => $run->case,
				'object' => [
					'register' => $this->settingsService->getConfigValue(key: 'register'),
					'schema' => $this->settingsService->getConfigValue(key: 'case_schema'),
					'id' => $run->caseId,
				],
				'metadata' => $run->metadata,
			],
			requestingApp: AppIdentity::APP_ID
		);
	}//end generationRequest()

	/**
	 * Filinq's refusal, or null when it stored a file.
	 *
	 * @param DocumentGenerationRequestedEvent $event The answered request.
	 *
	 * @return ActionResult|null The refusal.
	 */
	private function generationRefusal(DocumentGenerationRequestedEvent $event): ?ActionResult {
		$error = $event->getError();
		if ($error !== null && $error !== '') {
			return new ActionResult(succeeded: false, error: 'document_generation_failed: ' . $error);
		}

		return $this->resultRefusal(event: $event);
	}//end generationRefusal()

	/**
	 * No Filinq answered, or it stored no file: the refusal; null otherwise.
	 *
	 * @param DocumentGenerationRequestedEvent $event The answered request.
	 *
	 * @return ActionResult|null The refusal.
	 */
	private function resultRefusal(DocumentGenerationRequestedEvent $event): ?ActionResult {
		$result = $event->getResult();
		if ($event->isHandled() === false || $result === null) {
			return $this->filinqRequired();
		}

		if ((int)($result['fileId'] ?? 0) <= 0) {
			return new ActionResult(succeeded: false, error: 'document_generation_failed: Filinq stored no file');
		}

		return null;
	}//end resultRefusal()

	/**
	 * File the file Filinq stored on the case.
	 *
	 * @param GeneratedDocumentRun $run    The document being made.
	 * @param array<string, mixed> $result Filinq's result.
	 *
	 * @return ActionResult The outcome.
	 */
	private function fileGenerated(GeneratedDocumentRun $run, array $result): ActionResult {
		try {
			$created = $this->templating->filer->file(
				caseId: $run->caseId,
				fileId: (int)($result['fileId'] ?? 0),
				title: $run->name,
				mime: (string)($result['mime'] ?? 'application/pdf'),
				metadata: $run->metadata
			);
		} catch (Throwable $e) {
			return new ActionResult(succeeded: false, error: 'document_not_filed: ' . $e->getMessage());
		}

		return new ActionResult(
			succeeded: true,
			data: ['informatieobject' => (string)($created['id'] ?? ''), 'case' => $run->caseId]
		);
	}//end fileGenerated()

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
}//end class
