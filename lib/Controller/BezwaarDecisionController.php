<?php

/**
 * Deciding a bezwaar: the draft, and sending it to be decided.
 *
 * `DecisionService` has held `draft()`, `publish()` and `applyToBezwaar()`
 * since the bezwaar lifecycle shipped, with the whole Awb 7:11 disposition
 * matrix behind them, and NOTHING CALLED ANY OF THEM. No route, no listener,
 * no component. A jurist could hold a hearing, record its minutes and read the
 * advisory opinion, and then had nowhere to write the decision those three
 * steps exist to produce. This is the door.
 *
 * 🔴 EVERY ENDPOINT RESOLVES THE CASE FIRST. `CaseAccessGuard` answers per
 * case and an objection names its case, so the ordinary per-case guard applies
 * here exactly as it does on the hearing beside it. An unresolvable objection
 * denies: `#[NoAdminRequired]` with no per-object guard is an IDOR, and this
 * one would let anybody draft a decision on somebody else's objection.
 *
 * 🔴 PUBLISH IS NOT THE BESLUIT. dossiq does not author the besluit: publish()
 * raises a decidiq Decision and persists the returned reference, and the
 * besluit is materialised later from the concluded event. So this controller
 * answers the DRAFT it created and the reference it raised, and never a
 * decision document, because promising one here would be promising on another
 * app's behalf.
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
 * @spec openspec/specs/bezwaar-decision/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Bezwaar\DecisionService;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Draft a decision on an objection, and send it to be decided.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/bezwaar-decision/spec.md
 */
class BezwaarDecisionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string          $appName     The app name.
	 * @param IRequest        $request     The request.
	 * @param DecisionService $decisions   The Awb 7:11 decision on an objection.
	 * @param CaseAccessGuard $accessGuard Per-case authorization, failing closed.
	 * @param IUserSession    $userSession The session.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DecisionService $decisions,
		private readonly CaseAccessGuard $accessGuard,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Draft the decision on one objection.
	 *
	 * @param string $objectionId UUID of the bezwaar.
	 *
	 * @return JSONResponse The draft, or the refusal.
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	#[NoAdminRequired]
	public function draft(string $objectionId): JSONResponse {
		$denied = $this->denyUnlessHandler(caseId: $this->decisions->caseIdForObjection(objectionId: $objectionId));
		if ($denied !== null) {
			return $denied;
		}

		$payload = $this->request->getParam('decision');
		if (is_array($payload) === false) {
			return new JSONResponse(
				['error' => 'Name the disposition, the reasoning and the legal basis.'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		try {
			return new JSONResponse($this->decisions->draft(objectionId: $objectionId, payload: $payload));
		} catch (RuntimeException $e) {
			// THE VALIDATOR'S OWN SENTENCE, not a generic one. The Awb matrix
			// refuses for eight different reasons and a jurist told only "that
			// did not work" has to guess which of the eight.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end draft()

	/**
	 * Send a drafted decision to be decided.
	 *
	 * @param string $decisionId UUID of the bezwaarDecision.
	 *
	 * @return JSONResponse The record with its decision reference, or the refusal.
	 *
	 * @spec openspec/specs/bezwaar-decision/spec.md
	 */
	#[NoAdminRequired]
	public function publish(string $decisionId): JSONResponse {
		$denied = $this->denyUnlessHandler(caseId: $this->decisions->caseIdForDecision(decisionId: $decisionId));
		if ($denied !== null) {
			return $denied;
		}

		try {
			return new JSONResponse($this->decisions->publish(decisionId: $decisionId));
		} catch (RuntimeException $e) {
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end publish()

	/**
	 * The refusal for a caller who may not decide this objection, or null.
	 *
	 * @param string|null $caseId The case the objection belongs to.
	 *
	 * @return JSONResponse|null The refusal, or null when the caller may proceed.
	 */
	private function denyUnlessHandler(?string $caseId): ?JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($caseId === null
			|| $this->accessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false
		) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		return null;
	}//end denyUnlessHandler()
}//end class
