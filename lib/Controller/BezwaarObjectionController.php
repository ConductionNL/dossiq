<?php

/**
 * Opening an objection against a besluit.
 *
 * `BezwaarCreationHook::onBezwaarCreated()` links a bezwaar case to the primair
 * besluit it contests, relates the two cases and writes the objection record.
 * It has done all of that since the bezwaar workflow shipped and NOTHING CALLED
 * IT. `BezwaarLifecycleListener` observes the same schemas and only logs, so
 * there was no event path either: a bezwaar case could be created and never
 * learn which decision it was against.
 *
 * 🔴 IT IS A CONTROLLER AND NOT A LISTENER, and the reason is in the signature.
 * The hook needs the CONTESTED DECISION, and no create event carries one: which
 * besluit an objection is against is a judgement a jurist makes, not a fact the
 * case already holds. A listener would have to guess it, and a guessed
 * contested decision is an objection filed against the wrong besluit.
 *
 * 🔴 THE GUARD IS ON THE BEZWAAR CASE, which is the case being written. The
 * contested decision is only READ, and it is read as the calling user, so a
 * decision they may not see does not resolve and the act is refused rather than
 * leaking the besluit's existence.
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
 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\Bezwaar\BezwaarCreationHook;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use RuntimeException;

/**
 * Say which besluit a bezwaar case is against, and open the objection.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
 */
class BezwaarObjectionController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string              $appName     The app name.
	 * @param IRequest            $request     The request.
	 * @param BezwaarCreationHook $hook        Links the bezwaar to its primair besluit.
	 * @param CaseAccessGuard     $accessGuard Per-case authorization, failing closed.
	 * @param IUserSession        $userSession The session.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly BezwaarCreationHook $hook,
		private readonly CaseAccessGuard $accessGuard,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Open the objection on a bezwaar case, against a named besluit.
	 *
	 * @param string $caseId UUID of the bezwaar case.
	 *
	 * @return JSONResponse The objection record, or the refusal.
	 *
	 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
	 */
	#[NoAdminRequired]
	public function open(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->accessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['error' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		$contested = trim((string)$this->request->getParam('contestedDecision', ''));
		if ($contested === '') {
			return new JSONResponse(
				['error' => 'Name the besluit this objection is against.'],
				Http::STATUS_BAD_REQUEST,
			);
		}

		$payload = $this->request->getParam('objection');
		if (is_array($payload) === false) {
			$payload = [];
		}

		try {
			return new JSONResponse(
				$this->hook->onBezwaarCreated(
					objectionCaseId: $caseId,
					contestedDecisionId: $contested,
					objectionPayload: $payload,
				)
			);
		} catch (RuntimeException $e) {
			// The hook's own sentence. "Contested decision not found" and
			// "objection schema is not configured" are an operator's problem
			// and a jurist's problem, and one message over both helps neither.
			return new JSONResponse(['error' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}
	}//end open()
}//end class
