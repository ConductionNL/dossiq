<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Controller
 * @package   OCA\Dossiq\Controller
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceRefusal;
use OCA\Dossiq\Service\DecisionAdvice\DecisionAdviceService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Advice from the decision table a case type names, asked and decided on the case.
 *
 * Both actions write to the case's record, so both are guarded as a mutation
 * of the case, before anything is evaluated or stored.
 *
 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
 *
 * @psalm-suppress UnusedClass
 */
class DecisionAdviceController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string $appName App name.
	 * @param IRequest $request Request.
	 * @param DecisionAdviceService $advice Asks the table and records the choice.
	 * @param IUserSession $userSession The session.
	 * @param CaseAccessGuard $caseAccessGuard Per-case authorization (fails closed).
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly DecisionAdviceService $advice,
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuard $caseAccessGuard,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * What the advice dialog asks on a case: the case type's decision table, the
	 * inputs it declares with the values its rules use, and the outcomes a
	 * handler can choose. `available: false` when the case type names no table,
	 * which hides the case page's action.
	 *
	 * @param string $caseId The case.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md
	 */
	#[NoAdminRequired]
	public function form(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(message: 'Authenticatie vereist', status: Http::STATUS_UNAUTHORIZED);
		}

		$caseId = trim($caseId);
		if ($caseId === '') {
			return $this->error(message: 'caseId is verplicht', status: Http::STATUS_BAD_REQUEST);
		}

		if ($this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return $this->error(message: 'Not authorized', status: Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse($this->advice->form(caseId: $caseId));
		} catch (DecisionAdviceRefusal $e) {
			return $this->error(message: $e->getMessage(), status: $e->getStatus());
		}
	}//end form()

	/**
	 * Ask the case type's decision table for advice on a case.
	 *
	 * Body: `caseId` (string) and `inputs` (object, one value per input the table declares).
	 *
	 * @return JSONResponse The stored advice, or the refusal.
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
	 */
	#[NoAdminRequired]
	public function recommend(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(message: 'Authenticatie vereist', status: Http::STATUS_UNAUTHORIZED);
		}

		$caseId = trim((string)$this->request->getParam('caseId', ''));
		$inputs = $this->request->getParam('inputs', []);
		if ($caseId === '' || is_array($inputs) === false) {
			return $this->error(message: 'caseId en inputs zijn verplicht', status: Http::STATUS_BAD_REQUEST);
		}

		if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: $caseId, user: $user) === false) {
			return $this->error(message: 'Not authorized', status: Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse($this->advice->recommend(caseId: $caseId, inputs: $inputs, userId: $user->getUID()));
		} catch (DecisionAdviceRefusal $e) {
			return $this->error(message: $e->getMessage(), status: $e->getStatus());
		}
	}//end recommend()

	/**
	 * Accept the advice, or choose another outcome with a reason.
	 *
	 * Body: `adviceId` (string), `outcome` (string) and, for a deviation, `reason` (string).
	 *
	 * @return JSONResponse The advice with the choice, or the refusal.
	 *
	 * @spec openspec/changes/enforcement-intervention-from-the-case/specs/enforcement-lhs/spec.md#requirement-a-handler-asks-the-case-types-decision-table-for-advice-on-the-case-req-lhs-case-01
	 */
	#[NoAdminRequired]
	public function override(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return $this->error(message: 'Authenticatie vereist', status: Http::STATUS_UNAUTHORIZED);
		}

		$adviceId = trim((string)$this->request->getParam('adviceId', ''));
		if ($adviceId === '') {
			return $this->error(message: 'adviceId is verplicht', status: Http::STATUS_BAD_REQUEST);
		}

		try {
			$stored = $this->advice->find(adviceId: $adviceId);
			if ($this->caseAccessGuard->hasCaseMutationAccess(caseId: (string)$stored['case'], user: $user) === false) {
				return $this->error(message: 'Not authorized', status: Http::STATUS_FORBIDDEN);
			}

			return new JSONResponse(
				$this->advice->decide(
					advice: $stored,
					outcome: (string)$this->request->getParam('outcome', ''),
					reason: (string)$this->request->getParam('reason', ''),
					userId: $user->getUID(),
				)
			);
		} catch (DecisionAdviceRefusal $e) {
			return $this->error(message: $e->getMessage(), status: $e->getStatus());
		}//end try
	}//end override()

	/**
	 * A refusal with its message.
	 *
	 * @param string $message The message.
	 * @param int $status The status.
	 *
	 * @return JSONResponse The response.
	 */
	private function error(string $message, int $status): JSONResponse {
		return new JSONResponse(['error' => $message], $status);
	}//end error()
}//end class
