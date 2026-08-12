<?php

/**
 * Procest Advisory Body Controller
 *
 * Read-only REST API over the adviesorgaan (advisory body) directory: the
 * full list and a specialization-ranked search.
 *
 * Split out of ConsultationController along the resource seam — these
 * endpoints address `/api/advisory-bodies`, not a consultation, and depend
 * only on AdvisoryBodyService. Consultations reference an advisory body but
 * do not own it.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\AdvisoryBodyService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for the advisory body (adviesorgaan) directory.
 *
 * Both endpoints carry the NoAdminRequired annotation and require an
 * authenticated session.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
 */
class AdvisoryBodyController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name
	 * @param IRequest $request The request
	 * @param AdvisoryBodyService $advisoryBodyService The advisory body service
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly AdvisoryBodyService $advisoryBodyService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * List all advisory bodies.
	 *
	 * @return JSONResponse List of advisory bodies
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function listAdvisoryBodies(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$bodies = $this->advisoryBodyService->findAll();
		return new JSONResponse(['results' => $bodies]);
	}//end listAdvisoryBodies()

	/**
	 * Search advisory bodies by specialization tag.
	 *
	 * @return JSONResponse Ranked list of advisory bodies
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/consultation-management/tasks.md#TASK-CN-04
	 */
	public function searchAdvisoryBodies(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$query = (string)($this->request->getParam('q') ?? '');
		$bodies = $this->advisoryBodyService->searchBySpecialization(query: $query);
		return new JSONResponse(['results' => $bodies]);
	}//end searchAdvisoryBodies()
}//end class
