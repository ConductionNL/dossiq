<?php

/**
 * Starting from something somebody prepared earlier.
 *
 *  - GET  /api/case-templates                        the case templates on offer
 *  - POST /api/case-templates/{templateId}/start     start a case from one
 *  - GET  /api/content-templates/{kind}              the templates of one kind
 *  - GET  /api/content-templates/apply/{templateId}  the fields one presets
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Starter\CaseTemplateService;
use OCA\Dossiq\Service\Starter\ContentTemplateService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The handler's side of the template library.
 *
 * Unlike {@see StarterContentController} these are handler actions, not admin
 * settings: picking a task template is something the person doing the work
 * does. Each one carries a per-object guard in the body, because
 * `#[NoAdminRequired]` on its own means any authenticated user, and a template
 * is scoped to case types a given handler may not be near.
 *
 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
 */
class TemplateStartController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                 $appName  The app name.
	 * @param IRequest               $request  The HTTP request.
	 * @param CaseTemplateService    $cases    The case templates.
	 * @param ContentTemplateService $content  The template library.
	 * @param CaseAccessGuard        $guard    Who may write to a case.
	 * @param IUserSession           $session  Who is asking.
	 * @param LoggerInterface        $logger   Logger.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseTemplateService $cases,
		private readonly ContentTemplateService $content,
		private readonly CaseAccessGuard $guard,
		private readonly IUserSession $session,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The case templates a handler may start from.
	 *
	 * @return JSONResponse The templates.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
	 */
	#[NoAdminRequired]
	public function caseTemplates(): JSONResponse {
		if ($this->session->getUser() === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseTypeId = (string)$this->request->getParam('caseType', '');

		try {
			$templates = $this->cases->templates(caseTypeId: $caseTypeId);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: could not list case templates: ' . $e->getMessage());

			return new JSONResponse(['error' => 'Could not read the templates'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($templates === null) {
			return new JSONResponse(
				['error' => 'The register is not available'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse(['items' => $templates, 'total' => count($templates)]);
	}//end caseTemplates()

	/**
	 * Start a case from a template.
	 *
	 * @param string $templateId The template's id.
	 *
	 * @return JSONResponse The new case, or why it was refused.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
	 */
	#[NoAdminRequired]
	public function startFromTemplate(string $templateId): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// The per-object guard, on the template itself. A template is a case
		// row, so the right that governs reading it is the right that governs
		// reading any case, and a handler who may not see the bezwaar
		// templates may not start from one either.
		if ($this->guard->hasCaseReadAccess(caseId: $templateId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not allowed'], Http::STATUS_FORBIDDEN);
		}

		$overrides = $this->request->getParam('overrides', []);
		if (is_array($overrides) === false) {
			$overrides = [];
		}

		try {
			$result = $this->cases->startFrom(
				templateId: $templateId,
				overrides: $overrides,
			);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: could not start from a template: ' . $e->getMessage());

			return new JSONResponse(['error' => 'Could not start the case'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($result['ok'] === false) {
			$refused = Http::STATUS_CONFLICT;
			if ($result['reason'] === 'not_found') {
				$refused = Http::STATUS_NOT_FOUND;
			}

			return new JSONResponse($result, $refused);
		}

		return new JSONResponse($result, Http::STATUS_CREATED);
	}//end startFromTemplate()

	/**
	 * The templates of one kind, offered on one case type.
	 *
	 * @param string $kind The kind: document, mail, task, note, approval or result.
	 *
	 * @return JSONResponse The templates.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/template-library/spec.md
	 */
	#[NoAdminRequired]
	public function contentTemplates(string $kind): JSONResponse {
		$user = $this->session->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = (string)$this->request->getParam('case', '');
		if ($caseId !== '' && $this->guard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not allowed'], Http::STATUS_FORBIDDEN);
		}

		$caseTypeId = (string)$this->request->getParam('caseType', '');
		$search = (string)$this->request->getParam('search', '');

		try {
			$templates = $this->content->offered(kind: $kind, caseTypeId: $caseTypeId, search: $search);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: could not list content templates: ' . $e->getMessage());

			return new JSONResponse(['error' => 'Could not read the templates'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}

		if ($templates === null) {
			return new JSONResponse(
				['error' => 'The register is not available'],
				Http::STATUS_SERVICE_UNAVAILABLE
			);
		}

		return new JSONResponse(['items' => $templates, 'total' => count($templates)]);
	}//end contentTemplates()
}//end class
