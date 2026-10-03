<?php

/**
 * Dossiq CaseReassignmentController.
 *
 * Reads which open cases and tasks a reassignment away from one handler would
 * touch. Strictly read-only: it builds the selection and nothing else.
 *
 * The write that used to sit beside it is gone. A redistribution is one act of
 * OpenRegister's bulk job now, declared by
 * {@see \OCA\Dossiq\BulkAction\ReassignCasesAction} and started through
 * {@see SubstitutionController::releaseCaseload()} or the Cases page, so there
 * is one loop for every bulk act on cases and it is not in dossiq (D-1, D-6).
 *
 * Split out of SubstitutionController along the resource seam: this endpoint
 * addresses `/api/reassignments`, not a substitution, and is
 * coordinator-exclusive — a substitution is something a handler arranges for
 * themselves, whereas reading someone else's workload is not. It is
 * #[NoAdminRequired] with an explicit coordinator guard that fails closed
 * (ADR-005 Rule 3).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseReassignmentService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Controller for coordinator-only bulk case reassignment.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class CaseReassignmentController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName The app name.
	 * @param IRequest $request The request.
	 * @param CaseReassignmentService      $reassignmentService Bulk reassignment.
	 * @param IUserSession $userSession The user session.
	 * @param IGroupManager $groupManager Group manager (admin checks).
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseReassignmentService $reassignmentService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Preview a bulk reassignment. Coordinator-only.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	#[NoAdminRequired]
	public function reassignPreview(): JSONResponse {
		$guard = $this->requireCoordinator();
		if ($guard !== null) {
			return $guard;
		}

		try {
			$preview = $this->reassignmentService->preview(
				fromUser: (string)$this->request->getParam('fromUser', ''),
				filter: $this->reassignmentFilter()
			);
			return new JSONResponse($preview);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\Throwable $e) {
			$this->logger->error('Reassignment preview failed', ['error' => $e->getMessage()]);
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end reassignPreview()

	/**
	 * Build the optional reassignment filter from request params.
	 *
	 * @return array<string, mixed>|null
	 */
	private function reassignmentFilter(): ?array {
		$caseType = (string)$this->request->getParam('caseType', '');
		if ($caseType === '') {
			return null;
		}

		return ['caseType' => $caseType];
	}//end reassignmentFilter()

	/**
	 * Require a coordinator; returns a JSONResponse to short-circuit on failure.
	 *
	 * @return JSONResponse|null Null when the caller is a coordinator.
	 */
	private function requireCoordinator(): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authorised'], Http::STATUS_FORBIDDEN);
		}

		$userId = $user->getUID();
		if ($userId === '' || $this->groupManager->isAdmin($userId) === false) {
			return new JSONResponse(
				['error' => 'This action requires the coordinator role'],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end requireCoordinator()
}//end class
