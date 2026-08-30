<?php

/**
 * Dossiq Subsidie Controller.
 *
 * REST endpoints for the subsidieverlening-keten under `/api/subsidies`. All
 * authenticated endpoints require a logged-in user (`@NoAdminRequired`); the
 * underlying services are IDOR-safe (reads/writes scoped server-side, ids
 * validated). The terugvordering publication path is manager-gated. The
 * subsidieregister export is a public feed (Wet open overheid art. 3.3) that
 * returns only anonymised, already-published data.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Subsidie\BeschikkingService;
use OCA\Dossiq\Service\Subsidie\SubsidieService;
use OCA\Dossiq\Service\Subsidie\TussenrapportageService;
use OCA\Dossiq\Service\Subsidie\VaststellingService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller exposing the subsidy lifecycle endpoints.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — aggregates the four
 * subsidy lifecycle services it dispatches to.
 *
 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-06
 */
class SubsidieController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request.
	 * @param SubsidieService $subsidyService Core subsidy service.
	 * @param BeschikkingService $decisionService Grant-decision service.
	 * @param TussenrapportageService $tussenrapportage Interim-report service.
	 * @param VaststellingService $determinationService Settlement service.
	 * @param IUserSession $userSession The user session.
	 */
	public function __construct(
		IRequest $request,
		private readonly SubsidieService $subsidyService,
		private readonly BeschikkingService $decisionService,
		private readonly TussenrapportageService $tussenrapportage,
		private readonly VaststellingService $determinationService,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * List subsidieaanvragen with optional filters.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-06
	 */
	public function index(): JSONResponse {
		if ($this->requireUser() === null) {
			return $this->unauthorized();
		}

		$filters = [
			'status' => $this->request->getParam('status', ''),
			'subsidyScheme' => $this->request->getParam('regeling', ''),
			'handler' => $this->request->getParam('handler', ''),
		];

		try {
			$results = $this->subsidyService->listAanvragen($filters);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse(['results' => $results]);
	}//end index()

	/**
	 * Create a subsidieaanvraag.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-06
	 */
	public function create(): JSONResponse {
		$userId = $this->requireUser();
		if ($userId === null) {
			return $this->unauthorized();
		}

		$body = $this->bodyParams();
		// The behandelaar is the acting user unless explicitly assigned.
		if (((string)($body['handler'] ?? '')) === '') {
			$body['handler'] = $userId;
		}

		$term = (int)($body['termijnWeken'] ?? SubsidieService::DEFAULT_AANVRAAG_TERMIJN_WEKEN);
		unset($body['termijnWeken']);

		try {
			$request = $this->subsidyService->createAanvraag($body, $term);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($request, Http::STATUS_CREATED);
	}//end create()

	/**
	 * Transition a subsidieaanvraag to a new status.
	 *
	 * @param string $id The aanvraag id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-06
	 */
	public function transition(string $id): JSONResponse {
		if ($this->requireUser() === null) {
			return $this->unauthorized();
		}

		$toStatus = (string)$this->request->getParam('status', '');

		try {
			$request = $this->subsidyService->transitionAanvraag($id, $toStatus);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($request);
	}//end transition()

	/**
	 * Draft a beschikking for an aanvraag.
	 *
	 * @param string $id The aanvraag id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-06
	 */
	public function createBeschikking(string $id): JSONResponse {
		if ($this->requireUser() === null) {
			return $this->unauthorized();
		}

		$body = $this->bodyParams();
		$sequence = (int)($body['sequence'] ?? 1);
		unset($body['sequence']);

		try {
			$decision = $this->decisionService->createDraft($id, $body, $sequence);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($decision, Http::STATUS_CREATED);
	}//end createBeschikking()

	/**
	 * Publish a beschikking (legal effect; starts the bezwaartermijn).
	 *
	 * @param string $decisionId The beschikking id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-06
	 */
	public function publishBeschikking(string $decisionId): JSONResponse {
		if ($this->requireUser() === null) {
			return $this->unauthorized();
		}

		try {
			$decision = $this->decisionService->publish($decisionId);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($decision);
	}//end publishBeschikking()

	/**
	 * Sign a beschikking (signer derived from the session).
	 *
	 * @param string $decisionId The beschikking id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-06
	 */
	public function signBeschikking(string $decisionId): JSONResponse {
		if ($this->requireUser() === null) {
			return $this->unauthorized();
		}

		try {
			$decision = $this->decisionService->sign($decisionId);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($decision);
	}//end signBeschikking()

	/**
	 * Schedule an interim report on a grant execution, in status "expected".
	 *
	 * The auth posture mirrors createBeschikking() deliberately: the two are the
	 * same shape — an authenticated caseworker drafting a new lifecycle record
	 * on an existing parent, with the parent id coming from the route and the
	 * properties from the body. The service is IDOR-safe (register/schema are
	 * resolved server-side), so a stricter guard here would be inconsistent with
	 * every other create on this controller rather than safer.
	 *
	 * `status` and `amendementTeller` are set by the service, not the body.
	 *
	 * @param string $uitvoeringId The subsidieuitvoering id.
	 *
	 * @return JSONResponse The created tussenrapportage, 201.
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-06
	 */
	public function createTussenrapportage(string $uitvoeringId): JSONResponse {
		if ($this->requireUser() === null) {
			return $this->unauthorized();
		}

		try {
			$report = $this->tussenrapportage->createExpected(
				uitvoeringId: $uitvoeringId,
				payload: $this->bodyParams(),
			);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($report, Http::STATUS_CREATED);
	}//end createTussenrapportage()

	/**
	 * Approve an interim report.
	 *
	 * @param string $reportId The tussenrapportage id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-06
	 */
	public function approveTussenrapportage(string $reportId): JSONResponse {
		if ($this->requireUser() === null) {
			return $this->unauthorized();
		}

		$opinion = $this->request->getParam('beoordelingsoordeel', null);
		$amount = $this->request->getParam('approvedAmount', null);

		$opinionArg = null;
		if ($opinion !== null) {
			$opinionArg = (string)$opinion;
		}

		$amountArg = null;
		if ($amount !== null) {
			$amountArg = (float)$amount;
		}

		try {
			$report = $this->tussenrapportage->approveReport(
				reportId: $reportId,
				beoordelingsoordeel: $opinionArg,
				approvedAmount: $amountArg,
			);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($report);
	}//end approveTussenrapportage()

	/**
	 * Finalise a settlement (auto-triggers terugvordering when overpaid).
	 *
	 * @param string $determinationId The vaststelling id.
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @psalm-suppress PossiblyUnusedMethod
	 *
	 * @spec openspec/changes/subsidieverlening-keten/tasks.md#TASK-SUB-06
	 */
	public function finalizeVaststelling(string $determinationId): JSONResponse {
		if ($this->requireUser() === null) {
			return $this->unauthorized();
		}

		$granted = (float)$this->request->getParam('grantedAmount', 0);
		$actual = (float)$this->request->getParam('werkelijkeKosten', 0);
		$advances = (float)$this->request->getParam('totaalVoorschotten', 0);

		try {
			$result = $this->determinationService->finalize($determinationId, $granted, $actual, $advances);
		} catch (OCSBadRequestException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return new JSONResponse($result);
	}//end finalizeVaststelling()

	/**
	 * Resolve the authenticated user id, or null when unauthenticated.
	 *
	 * @return string|null The user id.
	 */
	private function requireUser(): ?string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return null;
		}

		return $user->getUID();
	}//end requireUser()

	/**
	 * Read the JSON / form body parameters, excluding routing params.
	 *
	 * @return array<string, mixed> The body parameters.
	 */
	private function bodyParams(): array {
		$params = $this->request->getParams();
		unset(
			$params['id'],
			$params['decisionId'],
			$params['reportId'],
			$params['uitvoeringId'],
			$params['vaststellingId'],
			$params['_route']
		);
		return $params;
	}//end bodyParams()

	/**
	 * Build a 401 Unauthorized response.
	 *
	 * @return JSONResponse
	 */
	private function unauthorized(): JSONResponse {
		return new JSONResponse(['error' => 'Authenticatie vereist'], Http::STATUS_UNAUTHORIZED);
	}//end unauthorized()
}//end class
