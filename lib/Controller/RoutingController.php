<?php

/**
 * Dossiq Routing Controller
 *
 * Action endpoint for manual recomputation of step assignees on a case.
 * Generic CRUD over routing rules themselves lives on the workflow template
 * and is served by the OpenRegister manifest renderer — this controller
 * only owns the engine actions `POST /api/cases/{id}/reroute` (recompute
 * every step's candidates) and `POST /api/cases/{id}/route` (route the case
 * to one person by a rule, arming the rule's take-back window).
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

use OCA\Dossiq\Controller\Support\AdminCaller;
use OCA\Dossiq\Controller\Support\CaseStepReroute;
use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\Contracts\CaseRouterInterface;
use OCA\Dossiq\Service\Support\ObjectArray;
use OCA\Dossiq\Service\Support\RowList;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Controller for routing engine actions.
 *
 * @spec openspec/changes/archive/2026-05-11-role-based-step-routing/tasks.md#T05
 *
 * @psalm-suppress UnusedClass
 */
class RoutingController extends Controller {
	use TranslatesRefusals;

	/**
	 * Constructor.
	 *
	 * @param string $appName App name
	 * @param IRequest $request Request
	 * @param CaseStepReroute $reroute Reads a case's workflow steps and resolves each step's rule
	 * @param CaseRouterInterface $router Routes a case to one person and arms its take-back window
	 * @param AdminCaller $caller The signed-in user, and whether they are an administrator
	 * @param LoggerInterface $logger Logger
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly CaseStepReroute $reroute,
		private readonly CaseRouterInterface $router,
		private readonly AdminCaller $caller,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Recompute every open step's assignees on a case.
	 *
	 * Loads the case, fetches its active workflowTemplate, walks the open
	 * steps, normalises each routing rule and resolves it against the case.
	 * Returns the list of affected step ids and their resolved assignees.
	 *
	 * Requires the caller to be a server admin (mapped to the spec's
	 * "case admin" notion). Non-admin callers receive 403.
	 *
	 * @param string $id The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @auth admin-only rerouting reassigns every open step on a case, so it is
	 * restricted to server admins — the body enforces the same rule via
	 * IGroupManager::isAdmin() and this declaration states it rather than
	 * contradicting it with @NoAdminRequired.
	 *
	 * @spec openspec/changes/archive/2026-05-11-role-based-step-routing/tasks.md#T05
	 */
	public function reroute(string $id): JSONResponse {
		$refusal = $this->adminRefusal();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			return $this->rerouteCase(id: $id);
		} catch (RefusedException $e) {
			return $this->refused(op: 'reroute on case ' . $id, e: $e);
		} catch (Throwable $e) {
			return $this->rerouteFailure(e: $e, id: $id);
		}//end try
	}//end reroute()

	/**
	 * Log a failed reroute and answer 500.
	 *
	 * @param Throwable $e The failure.
	 * @param string $id The case id.
	 *
	 * @return JSONResponse
	 */
	private function rerouteFailure(Throwable $e, string $id): JSONResponse {
		$this->logger->error(
			'Dossiq: reroute failed for case ' . $id . ': ' . $e->getMessage(),
		);
		return new JSONResponse(
			['error' => 'Herberekening mislukt'],
			Http::STATUS_INTERNAL_SERVER_ERROR,
		);
	}//end rerouteFailure()

	/**
	 * Refuse a caller that is not logged in or not a server admin.
	 *
	 * @return JSONResponse|null The refusal, or null for an admin.
	 */
	private function adminRefusal(): ?JSONResponse {
		if ($this->caller->getUser() === null) {
			return new JSONResponse(
				['error' => 'Authenticatie vereist'],
				Http::STATUS_UNAUTHORIZED,
			);
		}

		if ($this->caller->isAdmin() === false) {
			return new JSONResponse(
				['error' => 'Admin-rechten op de zaak vereist'],
				Http::STATUS_FORBIDDEN,
			);
		}

		return null;
	}//end adminRefusal()

	/**
	 * Recompute the assignees of every routable step on a case.
	 *
	 * @param string $id The case id.
	 *
	 * @return JSONResponse
	 *
	 * @throws RefusedException When a case's roles could not be read at all.
	 * @throws Throwable When the case or its workflow cannot be read.
	 */
	private function rerouteCase(string $id): JSONResponse {
		$affected = $this->reroute->affectedSteps(
			caseId: $id,
			toArray: $this->toArray(...),
			decodeSteps: $this->decodeSteps(...)
		);
		if ($affected === null) {
			return new JSONResponse(
				['error' => 'OpenRegister is niet beschikbaar'],
				Http::STATUS_SERVICE_UNAVAILABLE,
			);
		}

		return new JSONResponse(['caseId' => $id, 'affectedSteps' => $affected]);
	}//end rerouteCase()

	/**
	 * Route the case to one person by a routing rule.
	 *
	 * The body is the rule: `strategy`, `roleType` (or `roleTypes`), and
	 * optionally `team`, `areaTeams`, `fallback` and `takeBackAfter`
	 * ({value, unit: hours|businessDays|calendarDays}). The person picked
	 * becomes the case's assignee; the rule and the moment are kept on the
	 * case as `routing`, and a declared take-back window is armed. The
	 * assignee's first status move or edit accepts the case (decision 164).
	 *
	 * @param string $id The case UUID
	 *
	 * @return JSONResponse `{caseId, assignee, team, areaFallbackUsed, reason, takeBack}`
	 *
	 * @auth admin-only routing writes the assignee of a case
	 * whatever the caller's own role on it, so it is restricted to server
	 * admins, enforced in the body via IGroupManager::isAdmin().
	 *
	 * @spec openspec/changes/archive/2026-10-10-routing-by-weight-position-and-area/tasks.md
	 */
	public function route(string $id): JSONResponse {
		$refusal = $this->adminRefusal();
		if ($refusal !== null) {
			return $refusal;
		}

		$rule = $this->request->getParams();
		if (trim((string)($rule['strategy'] ?? '')) === '') {
			return new JSONResponse(['error' => 'Een routeringsregel heeft een strategie nodig'], Http::STATUS_BAD_REQUEST);
		}

		try {
			return new JSONResponse($this->router->route(caseId: $id, rule: $rule));
		} catch (RefusedException $e) {
			return $this->refused(op: 'route on case ' . $id, e: $e);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq: routing failed for case ' . $id . ': ' . $e->getMessage());
			return new JSONResponse(['error' => 'Routeren mislukt'], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}//end route()

	/**
	 * Decode a JSON-encoded steps payload into an array.
	 *
	 * @param mixed $value The raw value (string or array)
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function decodeSteps(mixed $value): array {
		return RowList::from(value: $value);
	}//end decodeSteps()

	/**
	 * Convert an arbitrary ObjectService return to an array.
	 *
	 * @param mixed $value The value
	 *
	 * @return array<string, mixed>
	 */
	private function toArray(mixed $value): array {
		return ObjectArray::of(value: $value);
	}//end toArray()
}//end class
