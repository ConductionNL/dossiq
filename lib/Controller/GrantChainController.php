<?php

/**
 * Dossiq GrantChainController.
 *
 * `GET /api/cases/{caseId}/grant-chain`: the executions of the case's grants,
 * each with its granted amount, advances, interim reports and settlements. The
 * case page's grant actions (record and assess an interim report, settle the
 * grant) read it to pick the row they act on; the writes go to the existing
 * `/api/subsidies` routes.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\GrantChainStepsInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * The grant chain of a case, for a reader of that case.
 *
 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
 */
class GrantChainController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string             $appName     The app name.
	 * @param IRequest           $request     The request.
	 * @param GrantChainStepsInterface $steps       Reads the grant chain.
	 * @param IUserSession       $userSession The user session.
	 * @param CaseAccessGuardInterface    $caseAccess  Per-case authorization guard.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly GrantChainStepsInterface $steps,
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuardInterface $caseAccess,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The executions of the case's grants, with what their next step needs.
	 *
	 * A case the user may not read answers 403 before anything is read.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
	 */
	#[NoAdminRequired]
	public function forCase(string $caseId): JSONResponse {
		$caseId = trim($caseId);
		$user   = $this->userSession->getUser();
		if ($user === null || $this->caseAccess->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'You may not read this case.'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse($this->steps->forCase(caseId: $caseId));
		} catch (Throwable) {
			return new JSONResponse(['message' => 'The grants of this case could not be read. Try again later.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end forCase()
}//end class
