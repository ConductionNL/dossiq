<?php

/**
 * Dossiq External Inspection Run Controller
 *
 * `POST /api/cases/{id}/checklist-runs/external` gives an external inspector,
 * someone without a Nextcloud account who works through the portal, a
 * checklist to run on a case (change inspection-checklists-onto-task, task
 * 4.3, design D6). The run is an OpenRegister task with performer type
 * `external`, so it shows in that inspector's portal task list.
 *
 * Authorized like the internal submit (TaskChecklistController::submit):
 * the case's stored assignee or an admin, decided from stored state before
 * the payload is read.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inspection-checklists-onto-task/design.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\ExternalInspectionRunInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Assigns a checklist run on a case to an external inspector.
 *
 * @spec openspec/changes/inspection-checklists-onto-task/design.md
 */
class ExternalInspectionRunController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                         $appName         The app name.
	 * @param IRequest                       $request         The request.
	 * @param IUserSession                   $userSession     User session.
	 * @param LoggerInterface                $logger          Logger.
	 * @param CaseAccessGuardInterface       $caseAccessGuard Per-case authorization (fails closed).
	 * @param ExternalInspectionRunInterface $runs            External runs as portal tasks.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly CaseAccessGuardInterface $caseAccessGuard,
		private readonly ExternalInspectionRunInterface $runs,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Give an external inspector a checklist to run on the case.
	 *
	 * Per-object guard: `CaseAccessGuard::hasCaseMutationAccess()`, asked
	 * before the payload is read.
	 *
	 * @param string $id UUID of the case.
	 *
	 * @return JSONResponse 201 the open run; 400, 403, 422 or 500 `{message}`.
	 *
	 * @throws OCSForbiddenException When nobody is signed in.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/inspection-checklists-onto-task/design.md
	 */
	#[NoAdminRequired]
	public function assign(string $id): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSForbiddenException('Not authenticated');
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $id, user: $user) === false) {
			return new JSONResponse(data: ['error' => 'Not authorized'], statusCode: Http::STATUS_FORBIDDEN);
		}

		$params = $this->request->getParams();
		$checklistId = $params['checklistId'] ?? '';
		$inspectorRef = $params['inspectorRef'] ?? '';
		if (is_string($checklistId) === false || trim($checklistId) === '' || is_string($inspectorRef) === false || trim($inspectorRef) === '') {
			return new JSONResponse(['message' => 'checklistId and inspectorRef are required'], Http::STATUS_BAD_REQUEST);
		}

		try {
			$run = $this->runs->assign(caseId: $id, templateId: $checklistId, inspectorRef: $inspectorRef, payload: $params, actor: $user->getUID());
			return new JSONResponse(data: $run, statusCode: Http::STATUS_CREATED);
		} catch (RuntimeException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_UNPROCESSABLE_ENTITY);
		} catch (Throwable $e) {
			$this->logger->error(
				'Failed to assign an external inspection run on case ' . $id . ': ' . $e->getMessage(),
				['app' => $this->appName]
			);
			return new JSONResponse(['message' => 'Assigning the run failed'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end assign()
}//end class
