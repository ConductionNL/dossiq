<?php

/**
 * Dossiq SettlementController.
 *
 * `GET /api/cases/{caseId}/settlements`: the settlements of the case, reached
 * through its applications and their executions. The case page draws its
 * settlement card from it.
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
 * @spec openspec/specs/case-page/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\SettlementOverviewInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;
use Throwable;

/**
 * The settlements of a case, for a reader of that case.
 *
 * @spec openspec/specs/case-page/spec.md
 */
class SettlementController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string             $appName     The app name.
	 * @param IRequest           $request     The request.
	 * @param SettlementOverviewInterface $overview    Reads the settlements.
	 * @param IUserSession       $userSession The user session.
	 * @param CaseAccessGuardInterface    $caseAccess  Per-case authorization guard.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly SettlementOverviewInterface $overview,
		private readonly IUserSession $userSession,
		private readonly CaseAccessGuardInterface $caseAccess,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * The settlements of the case.
	 *
	 * A case the user may not read answers 403 before anything is read.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/case-page/spec.md
	 */
	#[NoAdminRequired]
	public function forCase(string $caseId): JSONResponse {
		return $this->readFor(
			caseId: $caseId,
			read: fn (string $id): array => $this->overview->forCase(caseId: $id),
			failure: 'The settlement could not be read. Try again later.'
		);
	}//end forCase()

	/**
	 * The executions of the case's grants with what the grant actions need.
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
	public function grantChain(string $caseId): JSONResponse {
		return $this->readFor(
			caseId: $caseId,
			read: fn (string $id): array => $this->overview->chainForCase(caseId: $id),
			failure: 'The grants of this case could not be read. Try again later.'
		);
	}//end grantChain()

	/**
	 * Answer a read of the case for a reader of it, refusing anyone else first.
	 *
	 * @param string   $caseId  The case UUID, as routed.
	 * @param callable $read    Reads the answer for the trimmed case id.
	 * @param string   $failure What a failed read answers.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/grant-cases-from-application-to-settlement/tasks.md#1.2
	 */
	private function readFor(string $caseId, callable $read, string $failure): JSONResponse {
		$caseId = trim($caseId);
		$user   = $this->userSession->getUser();
		if ($user === null || $this->caseAccess->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'You may not read this case.'], Http::STATUS_FORBIDDEN);
		}

		try {
			return new JSONResponse($read($caseId));
		} catch (Throwable) {
			return new JSONResponse(['message' => $failure], Http::STATUS_SERVICE_UNAVAILABLE);
		}
	}//end readFor()
}//end class
