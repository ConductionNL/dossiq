<?php

/**
 * Dossiq LHS Controller
 *
 * Action endpoint for the LHS recommendation engine. CRUD over the
 * `lhsMatrix` and `lhsRecommendation` schemas is served by the OpenRegister
 * manifest renderer — this controller only owns the engine actions:
 *   - POST /api/lhs/recommend (matrix lookup + persistence)
 *   - POST /api/lhs/recommendations/{id}/override (apply inspector override)
 *     Body: recommendationId (or a `recommendation` object, from which only the
 *     id is read), intervention, justification, and optionally userRole.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\LhsLookupServiceInterface;
use OCA\Dossiq\Kernel\Contracts\LhsRecommendationServiceInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Controller for LHS engine actions.
 *
 * @spec openspec/changes/archive/2026-05-11-enforcement-lhs/tasks.md#T03
 *
 * @psalm-suppress UnusedClass
 */
class LhsController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param string $appName App name
	 * @param IRequest $request Request
	 * @param LhsRecommendationServiceInterface $lhsService LHS engine
	 * @param LhsLookupServiceInterface $lhsLookupService LHS simple lookup service
	 * @param IUserSession $userSession User session
	 * @param IGroupManager $groupManager Group manager
	 * @param LoggerInterface $logger Logger
	 * @param CaseAccessGuardInterface $caseAccessGuard Per-case authorization (fails closed)
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly LhsRecommendationServiceInterface $lhsService,
		private readonly LhsLookupServiceInterface $lhsLookupService,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
		private readonly CaseAccessGuardInterface $caseAccessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Recommend an intervention for a (case, ernst, gedrag, actorType) triple.
	 *
	 * Body parameters:
	 *   - caseId (string, required)
	 *   - ernst (string, required)
	 *   - gedrag (string, required)
	 *   - actorType (string, required)
	 *   - lhsVersion (int, optional)
	 *   - inspection (string, optional)
	 *
	 * @return JSONResponse The persisted lhsRecommendation row
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-05-11-enforcement-lhs/tasks.md#T03
	 */
	public function recommend(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthenticated();
		}

		return $this->recommendAs(user: $user);
	}//end recommend()

	/**
	 * Read the triple, check the caller may write on the case, and recommend.
	 *
	 * @param IUser $user The signed-in user.
	 *
	 * @return JSONResponse The persisted lhsRecommendation row, or the refusal
	 *
	 * @spec openspec/changes/archive/2026-05-11-enforcement-lhs/tasks.md#T03
	 */
	private function recommendAs(IUser $user): JSONResponse {
		$caseId = (string)$this->request->getParam('caseId', '');
		$severity = (string)$this->request->getParam('severity', '');
		$behaviour = (string)$this->request->getParam('behaviour', '');
		$actorType = (string)$this->request->getParam('actorType', '');
		if (in_array('', [$caseId, $severity, $behaviour, $actorType], true) === true) {
			return $this->badRequest(error: 'caseId, ernst, gedrag en actorType zijn verplicht');
		}

		// This endpoint reads as a query but PERSISTS an `lhsRecommendation`
		// (an enforcement-sanction record) against the named case, so it is
		// guarded as a mutation.
		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		return $this->recommendFor(caseId: $caseId, severity: $severity, behaviour: $behaviour, actorType: $actorType);
	}//end recommendAs()

	/**
	 * Persist the recommendation for the triple on the case.
	 *
	 * @param string $caseId    The case.
	 * @param string $severity  The ernst.
	 * @param string $behaviour The gedrag.
	 * @param string $actorType The actor type.
	 *
	 * @return JSONResponse The persisted lhsRecommendation row, or the failure
	 *
	 * @spec openspec/changes/archive/2026-05-11-enforcement-lhs/tasks.md#T03
	 */
	private function recommendFor(string $caseId, string $severity, string $behaviour, string $actorType): JSONResponse {
		try {
			$row = $this->lhsService->recommend(
				caseId: $caseId,
				severity: $severity,
				behaviour: $behaviour,
				actorType: $actorType,
				lhsVersion: $this->requestedVersion(),
				inspection: $this->requestedInspection(),
			);
			return new JSONResponse($row);
		} catch (Throwable $e) {
			return $this->recommendFailed(exception: $e);
		}
	}//end recommendFor()

	/**
	 * The LHS version the request pins, or null for the current one.
	 *
	 * @return int|null
	 *
	 * @spec openspec/changes/archive/2026-05-11-enforcement-lhs/tasks.md#T03
	 */
	private function requestedVersion(): ?int {
		$versionParam = $this->request->getParam('lhsVersion');
		if ($versionParam === null) {
			return null;
		}

		return (int)$versionParam;
	}//end requestedVersion()

	/**
	 * The inspection the recommendation follows from, or null.
	 *
	 * @return string|null
	 *
	 * @spec openspec/changes/archive/2026-05-11-enforcement-lhs/tasks.md#T03
	 */
	private function requestedInspection(): ?string {
		$inspection = $this->request->getParam('inspection');
		if (is_string($inspection) === true && $inspection !== '') {
			return $inspection;
		}

		return null;
	}//end requestedInspection()

	/**
	 * The answer to a failed recommendation: the engine's refusal as 422, anything else a logged 500.
	 *
	 * @param Throwable $exception The failure.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-05-11-enforcement-lhs/tasks.md#T03
	 */
	private function recommendFailed(Throwable $exception): JSONResponse {
		if ($exception instanceof RuntimeException) {
			return new JSONResponse(
				['error' => $exception->getMessage()],
				Http::STATUS_UNPROCESSABLE_ENTITY,
			);
		}

		$this->logger->error('Dossiq LHS recommend failed: ' . $exception->getMessage());
		return $this->serverError(error: 'LHS-aanbeveling mislukt');
	}//end recommendFailed()

	/**
	 * A 400 with its error.
	 *
	 * @param string $error The error.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-05-11-enforcement-lhs/tasks.md#T03
	 */
	private function badRequest(string $error): JSONResponse {
		return new JSONResponse(['error' => $error], Http::STATUS_BAD_REQUEST);
	}//end badRequest()

	/**
	 * A 500 with its error.
	 *
	 * @param string $error The error.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-05-11-enforcement-lhs/tasks.md#T03
	 */
	private function serverError(string $error): JSONResponse {
		return new JSONResponse(['error' => $error], Http::STATUS_INTERNAL_SERVER_ERROR);
	}//end serverError()

	/**
	 * The 401 every LHS endpoint answers a caller without a session.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-05-11-enforcement-lhs/tasks.md#T03
	 */
	private function unauthenticated(): JSONResponse {
		return new JSONResponse(
			['error' => 'Authenticatie vereist'],
			Http::STATUS_UNAUTHORIZED,
		);
	}//end unauthenticated()

	/**
	 * The id of the recommendation an override applies to.
	 *
	 * The ID, not the row. The service reads the recommendation back from the
	 * store, because the escalation guard compares against what the MATRIX
	 * recommended — a value a request must not be able to supply.
	 *
	 * A whole `recommendation` object is still accepted for callers that have
	 * not been updated, but ONLY its id is read from it; everything else in it
	 * is ignored.
	 *
	 * @return string The id, or an empty string when none was usable.
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	private function resolveRecommendationId(): string {
		$recommendationId = (string)$this->request->getParam('recommendationId', '');
		if ($recommendationId !== '') {
			return $recommendationId;
		}

		$recommendation = $this->request->getParam('recommendation');
		if (is_array($recommendation) === false) {
			return '';
		}

		return (string)($recommendation['id'] ?? ($recommendation['@self']['id'] ?? ''));

	}//end resolveRecommendationId()

	/**
	 * Map an engine refusal onto its HTTP status.
	 *
	 * An escalation refused for want of the manager role is a 403 and not a
	 * 422: the request was well-formed and the caller simply may not do it, and
	 * a client that cannot tell those apart will offer a retry that can never
	 * succeed.
	 *
	 * @param RuntimeException $exception The engine's refusal.
	 *
	 * @return JSONResponse The mapped response.
	 *
	 * @spec openspec/specs/enforcement-lhs/spec.md
	 */
	private function overrideRefusal(RuntimeException $exception): JSONResponse {
		$message = $exception->getMessage();
		$status = Http::STATUS_UNPROCESSABLE_ENTITY;
		if ($message === 'Verzwaring vereist managerrol') {
			$status = Http::STATUS_FORBIDDEN;
		}

		return new JSONResponse(['error' => $message], $status);

	}//end overrideRefusal()

	/**
	 * Override an existing LHS recommendation.
	 *
	 * Body parameters:
	 *   - recommendation (array, required) — the original row including id
	 *   - intervention   (string, required)
	 *   - justification  (string, required, >= 20 chars)
	 *
	 * Manager role is auto-detected from the Nextcloud admin group; UI may
	 * additionally pass `userRole` for explicit selection.
	 *
	 * @return JSONResponse The updated lhsRecommendation row
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-05-11-enforcement-lhs/tasks.md#T03
	 */
	public function override(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthenticated();
		}

		$recommendationId = $this->resolveRecommendationId();
		$intervention = (string)$this->request->getParam('intervention', '');
		$justification = (string)$this->request->getParam('justification', '');
		$hasBlank = in_array('', [$recommendationId, $intervention, $justification], true);
		if ($hasBlank === true) {
			return $this->badRequest(error: 'recommendationId, intervention en justification zijn verplicht');
		}

		try {
			$updated = $this->lhsService->override(
				recommendationId: $recommendationId,
				intervention: $intervention,
				justification: $justification,
				userRole: $this->roleOf(user: $user),
			);
		} catch (RuntimeException $e) {
			return $this->overrideRefusal(exception: $e);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq LHS override failed: ' . $e->getMessage());
			return $this->serverError(error: 'LHS-override mislukt');
		}//end try

		return new JSONResponse($updated);
	}//end override()

	/**
	 * The role the override is weighed under: `manager` only for a caller who
	 * declares it AND is an admin; everyone else is an `inspector`.
	 *
	 * @param IUser $user The caller.
	 *
	 * @return string `manager` or `inspector`.
	 *
	 * @spec openspec/changes/archive/2026-05-11-enforcement-lhs/tasks.md#T03
	 */
	private function roleOf(IUser $user): string {
		$declaredRole = (string)$this->request->getParam('userRole', '');
		$isManager = $this->groupManager->isAdmin($user->getUID());
		if ($declaredRole === 'manager' && $isManager === true) {
			return 'manager';
		}

		return 'inspector';
	}//end roleOf()

	/**
	 * Look up an LHS interventieladder step for a gedrag × gevolg combination.
	 *
	 * Query parameters:
	 *   - gedrag (string, required): A | B | C | D
	 *   - gevolg (string, required): 1 | 2 | 3 | 4
	 *
	 * @return JSONResponse The matching lhsMatrixCell {gedragRow, gevolgColumn, interventieStep, description}
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-06-13-vth-module/tasks.md#task-8
	 */
	public function lookup(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->unauthenticated();
		}

		$behaviour = (string)$this->request->getParam('behaviour', '');
		$gevolg = (string)$this->request->getParam('gevolg', '');

		if ($behaviour === '' || $gevolg === '') {
			return $this->badRequest(error: 'gedrag en gevolg zijn verplicht');
		}

		try {
			$cell = $this->lhsLookupService->lookup(behaviour: $behaviour, gevolg: $gevolg);
		} catch (RuntimeException $e) {
			return $this->badRequest(error: $e->getMessage());
		} catch (Throwable $e) {
			$this->logger->error('Dossiq LHS lookup failed: ' . $e->getMessage());
			return $this->serverError(error: 'LHS opzoeken mislukt');
		}

		return new JSONResponse($cell);
	}//end lookup()
}//end class
