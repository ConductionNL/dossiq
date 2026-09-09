<?php

/**
 * Dossiq CreateDocumentHandler
 *
 * Renders a document template against the case (merge fields) and files the
 * result in the case dossier through ZaakdossierService::uploadDocument(),
 * the same two writes MergeTemplateHandler makes for a generated letter, so
 * the document lands on the Documents tab beside the files people dropped
 * there. In dry-run mode it returns the rendered output without persisting
 * any file.
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
use OCA\Dossiq\Service\ZaakdossierService;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Handler for `createDocument` automatic actions.
 *
 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
 */
class CreateDocumentHandler implements ActionHandlerInterface {
	use HandlesTemplates;

	/**
	 * Constructor for CreateDocumentHandler.
	 *
	 * @param ContainerInterface $container DI container, used to resolve
	 *                                      ZaakdossierService lazily. This
	 *                                      handler is built whenever the Flow
	 *                                      node catalogue is read, and a
	 *                                      constructor dependency would drag
	 *                                      the whole dossier stack (settings,
	 *                                      file storage, access guard) into
	 *                                      every catalogue read.
	 * @param IUserSession $userSession Supplies the author of a generated
	 *                                  document.
	 * @param LoggerInterface $logger PSR-3 logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ContainerInterface $container,
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
		return 'createDocument';
	}//end type()

	/**
	 * {@inheritDoc}
	 *
	 * @param array $actionConfig Resolved action config array.
	 * @param array $case The full case object.
	 * @param array $transitionContext Transition context (carries dryRun).
	 *
	 * @return ActionResult The outcome of the document creation.
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handle(array $actionConfig, array $case, array $transitionContext): ActionResult {
		try {
			$templateSlug = (string)($actionConfig['templateSlug'] ?? '');
			$outputName = $this->renderTemplate(
				template: (string)($actionConfig['outputName'] ?? 'document.md'),
				case: $case
			);
			$mergeFields = (array)($actionConfig['mergeFields'] ?? []);
			$renderedFields = [];
			foreach ($mergeFields as $key => $tpl) {
				$renderedFields[(string)$key] = $this->renderTemplate(template: (string)$tpl, case: $case);
			}

			// The merge fields join the case context under their own root, so
			// a template writes `{{case.mergeFields.besluit}}` for a value the
			// action computed and `{{case.title}}` for one the case holds.
			// Under their own root they cannot shadow a real case field.
			$context = array_merge($case, ['mergeFields' => $renderedFields]);
			$body = $this->renderTemplate(template: $templateSlug, case: $context);

			$preview = [
				'templateSlug' => $templateSlug,
				'outputName' => $outputName,
				'mergeFields' => $renderedFields,
				'body' => $body,
			];

			if (($transitionContext['dryRun'] ?? false) === true) {
				return new ActionResult(succeeded: true, data: $preview);
			}

			if ($templateSlug === '') {
				return new ActionResult(succeeded: false, error: 'missing_template_slug', data: $preview);
			}

			$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
			if ($caseId === '') {
				return new ActionResult(succeeded: false, error: 'missing_case_id', data: $preview);
			}

			// The dossier requires a document type, and so does the schema. A
			// template that names none cannot be filed, and saying so beats
			// guessing a type for a letter that goes out under the council's
			// name. Same refusal MergeTemplateHandler makes.
			$documentType = (string)($actionConfig['documentType'] ?? '');
			if ($documentType === '') {
				return new ActionResult(succeeded: false, error: 'missing_document_type', data: $preview);
			}

			// A letter with a hole where the addressee should be is worse than
			// no letter, so an unresolvable placeholder refuses BEFORE the
			// first write and the dossier stays exactly as it was.
			$missing = $this->missingTemplateFields(template: $templateSlug, case: $context);
			if ($missing !== []) {
				return new ActionResult(
					succeeded: false,
					error: 'missing_template_field:' . $missing[0],
					data: $preview
				);
			}

			$dossier = $this->resolveDossierService();
			if ($dossier === null) {
				return new ActionResult(succeeded: false, error: 'document_service_unavailable', data: $preview);
			}

			$created = $dossier->uploadDocument(
				$caseId,
				$outputName,
				$body,
				[
					'title' => $outputName,
					'informatieobjecttype' => $documentType,
					'direction' => 'outgoing',
					'auteur' => $this->currentAuthor(),
					'format' => 'text/markdown',
				]
			);

			$preview['documentId'] = (string)($created['id'] ?? '');
			$preview['case'] = $caseId;

			return new ActionResult(succeeded: true, data: $preview);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CreateDocumentHandler: failed to render document',
				[
					'app' => Application::APP_ID,
					'slug' => (string)($actionConfig['slug'] ?? ''),
					'exception' => $e->getMessage(),
				]
			);
			return new ActionResult(succeeded: false, error: 'document_create_failed');
		}//end try
	}//end handle()

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
	 * Resolve dossiq's own ZaakdossierService lazily, and typed.
	 *
	 * Typed on purpose. The soft binding this replaced asked
	 * `method_exists($service, 'renderAndAttach')`, a method ZgwDocumentService
	 * has never had, and answered `succeeded: true, documentId: null` when the
	 * answer was no. An `instanceof` narrows the return so the call below is
	 * analysed against the real signature.
	 *
	 * @return ZaakdossierService|null The service, or null when unavailable.
	 */
	private function resolveDossierService(): ?ZaakdossierService {
		try {
			$service = $this->container->get(ZaakdossierService::class);
		} catch (\Throwable $e) {
			return null;
		}

		if ($service instanceof ZaakdossierService) {
			return $service;
		}

		return null;
	}//end resolveDossierService()
}//end class
