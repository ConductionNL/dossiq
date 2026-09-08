<?php

/**
 * Dossiq MergeTemplateHandler
 *
 * Renders a text/markdown template into a case field via ObjectService.
 * In dry-run mode it returns the rendered content + target field without
 * persisting any update.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Actions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Actions;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseFieldWriter;
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\IAppConfig;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handler for `mergeTemplate` automatic actions.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class MergeTemplateHandler implements ActionHandlerInterface {
	use HandlesTemplates;

	/**
	 * Constructor for MergeTemplateHandler.
	 *
	 * @param ContainerInterface $container DI container — used to resolve
	 *                                      OpenRegister ObjectService.
	 * @param IAppConfig $appConfig App config — supplies register +
	 *                              case_schema keys for the save.
	 * @param CaseFieldWriter $caseWriter Applies ONLY the target field to the
	 *                                    stored case.
	 * @param IUserSession $userSession Supplies the author of a generated
	 *                                  document.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly CaseFieldWriter $caseWriter,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * {@inheritDoc}
	 *
	 * @return string The action type slug handled by this handler.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function type(): string {
		return 'mergeTemplate';
	}//end type()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $actionConfig Resolved action config array.
	 * @param array $case The full case object.
	 * @param array $transitionContext Transition context (carries dryRun).
	 *
	 * @return ActionResult The outcome of the template merge.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$template = (string)($actionConfig['template'] ?? ($actionConfig['templateSlug'] ?? ''));
			$targetField = (string)($actionConfig['targetField'] ?? '');
			$rendered = $this->renderTemplate(template: $template, case: $case);

			$preview = [
				'targetField' => $targetField,
				'rendered' => $rendered,
			];

			if (($transitionContext['dryRun'] ?? false) === true) {
				return new ActionResult(succeeded: true, data: $preview);
			}

			// NO targetField means "file this in the dossier". The action that
			// generates a letter from the case has no field to write into; the
			// rendered result IS the document. The targetField branch below is
			// untouched, so every existing flow behaves exactly as before.
			if ($targetField === '') {
				return $this->storeAsInformatieobject(
					actionConfig: $actionConfig,
					case: $case,
					template: $template,
					rendered: $rendered,
					preview: $preview
				);
			}

			$objectService = $this->resolveObjectService();
			if ($objectService === null) {
				return new ActionResult(succeeded: false, error: 'object_service_unavailable', data: $preview);
			}

			$register = $this->appConfig->getValueString(
				Application::APP_ID,
				'register',
				''
			);
			$schema = $this->appConfig->getValueString(
				Application::APP_ID,
				'case_schema',
				''
			);

			if ($register === '' || $schema === '') {
				return new ActionResult(succeeded: false, error: 'case_schema_unconfigured', data: $preview);
			}

			// On the flow path the engine's RegistryStepDispatcher already
			// runs this handler inside `ObjectService::runAs()` as the run's
			// acting identity (openregister#3332) — the seam that unstuck the
			// seeded case flow FlowRunWorker refused as 'Anonymous'. On the
			// interactive path the ambient session user answers the permission
			// checks. No local runAs wrap is needed here any more.
			//
			// ONLY the target field is written. `$case` is a snapshot of the
			// flow item; full-saving `array_merge($case, ...)` here wrote the
			// document over whatever other writers had stored since the
			// snapshot, and dropped every snapshot field the schema does not
			// declare (the commissieBesluit silent-drop, measured live).
			$this->caseWriter->write(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				case: $case,
				changes: [$targetField => $rendered]
			);

			// The rendered document ALSO travels on the result, so the flow
			// node can stamp it onto the outgoing item: the next step's
			// snapshot must already carry what this step just stored, or that
			// step reasons from a case that predates its own flow.
			return new ActionResult(
				succeeded: true,
				data: $preview,
				caseChanges: [$targetField => $rendered]
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'MergeTemplateHandler: failed to merge template',
				[
					'app' => Application::APP_ID,
					'slug' => (string)($actionConfig['slug'] ?? ''),
					'exception' => $e->getMessage(),
				]
			);
			return new ActionResult(succeeded: false, error: 'merge_template_failed');
		}//end try
	}//end handle()

	/**
	 * File the rendered template in the case dossier.
	 *
	 * Writes an `informatieobject` with status draft, direction outgoing, the
	 * signed-in user as author and the template's name as title, and links it
	 * to the case with a `zaakinformatieobject` — the two writes
	 * {@see ZaakdossierService::uploadDocument()} already makes for an upload,
	 * so the generated letter lands on the Documents tab beside the files
	 * people dropped there.
	 *
	 * Every refusal happens BEFORE the first write, so a failed generation
	 * leaves the dossier exactly as it was.
	 *
	 * @param array $actionConfig Resolved action config array.
	 * @param array $case The full case object.
	 * @param string $template The raw template body.
	 * @param string $rendered The rendered result.
	 * @param array $preview The result payload shared with the other branch.
	 *
	 * @return ActionResult The outcome.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/specs/template-library/spec.md
	 */
	private function storeAsInformatieobject(
		array $actionConfig,
		array $case,
		string $template,
		string $rendered,
		array $preview,
	): ActionResult {
		$missing = $this->missingTemplateFields(template: $template, case: $case);
		if ($missing !== []) {
			return new ActionResult(
				succeeded: false,
				error: 'missing_template_field:' . $missing[0],
				data: $preview
			);
		}

		$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
		if ($caseId === '') {
			return new ActionResult(succeeded: false, error: 'missing_case_id', data: $preview);
		}

		// The dossier requires a document type, and so does the schema. A
		// template that names none cannot be filed, and saying so beats
		// guessing a type for a letter that goes out under the council's name.
		$documentType = (string)($actionConfig['documentType'] ?? '');
		if ($documentType === '') {
			return new ActionResult(succeeded: false, error: 'missing_document_type', data: $preview);
		}

		$dossier = $this->resolveZaakdossierService();
		if ($dossier === null) {
			return new ActionResult(succeeded: false, error: 'dossier_service_unavailable', data: $preview);
		}

		$name = $this->templateName(actionConfig: $actionConfig);
		$created = $dossier->uploadDocument(
			$caseId,
			$this->fileNameFor(name: $name),
			$rendered,
			[
				'title' => $name,
				'informatieobjecttype' => $documentType,
				'direction' => 'outgoing',
				'auteur' => $this->currentAuthor(),
				'format' => 'text/markdown',
			]
		);

		return new ActionResult(
			succeeded: true,
			data: array_merge($preview, ['informatieobject' => (string)($created['id'] ?? ''), 'case' => $caseId])
		);
	}//end storeAsInformatieobject()

	/**
	 * The template's display name, which becomes the document's title.
	 *
	 * `templateSlug` carries the template BODY on this handler (it is passed
	 * straight to the renderer), so the name travels separately.
	 *
	 * @param array $actionConfig Resolved action config array.
	 *
	 * @return string The name.
	 */
	private function templateName(array $actionConfig): string {
		$name = trim((string)($actionConfig['templateName'] ?? ($actionConfig['name'] ?? '')));
		if ($name !== '') {
			return $name;
		}

		return 'Document';
	}//end templateName()

	/**
	 * A filesystem-safe filename for the generated document.
	 *
	 * @param string $name The template name.
	 *
	 * @return string The filename.
	 */
	private function fileNameFor(string $name): string {
		$slug = strtolower((string)preg_replace('/[^A-Za-z0-9]+/', '-', $name));
		$slug = trim($slug, '-');
		if ($slug === '') {
			$slug = 'document';
		}

		return $slug . '.md';
	}//end fileNameFor()

	/**
	 * The signed-in user's display name, for the document's author.
	 *
	 * Empty on a background run with no session, which the schema allows:
	 * `auteur` is optional, and an empty author is honest where a fabricated
	 * one is not.
	 *
	 * @return string The display name, or empty.
	 */
	private function currentAuthor(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getDisplayName();
	}//end currentAuthor()

	/**
	 * Resolve OpenRegister ObjectService lazily.
	 *
	 * @return object|null
	 */
	private function resolveObjectService(): ?object {
		try {
			return $this->container->get('OCA\OpenRegister\Service\ObjectService');
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveObjectService()

	/**
	 * Resolve dossiq's own ZaakdossierService lazily.
	 *
	 * Through the container rather than the constructor for the same reason
	 * ObjectService is: this handler is built whenever the Flow node catalogue
	 * is, and a constructor dependency would drag the whole dossier stack —
	 * settings, file storage, access guard — into every catalogue read.
	 *
	 * @return ZaakdossierService|null The service, or null when unavailable.
	 */
	private function resolveZaakdossierService(): ?ZaakdossierService {
		try {
			$service = $this->container->get(ZaakdossierService::class);
		} catch (\Throwable $e) {
			return null;
		}

		if ($service instanceof ZaakdossierService) {
			return $service;
		}

		return null;
	}//end resolveZaakdossierService()
}//end class
