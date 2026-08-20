<?php

/**
 * Procest Dossier Export Controller.
 *
 * REST surface for exporting a beroep (or bezwaar) dossier for submission
 * to the bestuursrechter. Exposes a single endpoint:
 *
 *  - GET /api/cases/{caseId}/dossier-export
 *
 * The endpoint returns the AWB-conventionally ordered, sequentially named
 * export plan built by {@see BeroepDossierExport}. Document gathering goes
 * through the OpenRegister ObjectService, which enforces the caller's RBAC
 * — the controller never bypasses per-object access control, so a user can
 * only export documents they are permitted to read (IDOR-safe).
 *
 * Error responses use static messages — `$e->getMessage()` is NEVER
 * returned to the client.
 *
 * @category Controller
 * @package  OCA\Procest\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\Procest\Controller;

use OCA\Procest\Service\BeroepDossierExport;
use OCA\Procest\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for the dossier-export endpoint.
 *
 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
 */
class DossierExportController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The HTTP request.
	 * @param BeroepDossierExport $fileExport The export service.
	 * @param IUserSession $userSession The current session.
	 * @param LoggerInterface $logger The logger.
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed).
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly BeroepDossierExport $fileExport,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Build the ordered dossier export plan for a case.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse The export plan, or an error response.
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
	 */
	public function export(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if (trim($caseId) === '') {
			return new JSONResponse(['error' => 'A case id is required'], Http::STATUS_BAD_REQUEST);
		}

		// The plan spans every case linked via `_sourceCase`, so one
		// unauthorised export walks a whole bezwaar/beroep chain.
		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		try {
			$plan = $this->fileExport->buildPlan(caseId: $caseId);
			return new JSONResponse($plan);
		} catch (\Throwable $e) {
			$this->logger->error(
				'DossierExportController: export failed',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);
			return new JSONResponse(
				['error' => 'Could not build dossier export'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}
	}//end export()
}//end class
