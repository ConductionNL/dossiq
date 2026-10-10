<?php

/**
 * Dossiq Woo delivered set controller
 *
 * GET /api/cases/{id}/woo/delivered-sets/{setId}/verify recomputes the
 * hashes of a delivered set (woo-delivered-set-is-a-record REQ-WDS-003). It
 * needs read access to the case, and the set must belong to that case.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Woo\WooDeliveredSetVerifier;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Verifies a delivered set for a user who may read its case.
 *
 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
 */
class WooDeliveredSetController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param string                  $appName     The app name.
	 * @param IRequest                $request     The request.
	 * @param WooDeliveredSetVerifier $verifier    Recomputes the hashes.
	 * @param CaseAccessGuard         $guard       Case read access.
	 * @param IUserSession            $userSession The caller.
	 */
	public function __construct(
		string $appName,
		IRequest $request,
		private readonly WooDeliveredSetVerifier $verifier,
		private readonly CaseAccessGuard $guard,
		private readonly IUserSession $userSession,
	) {
		parent::__construct(appName: $appName, request: $request);
	}//end __construct()

	/**
	 * Verify one set of one case.
	 *
	 * @param string $id    The case.
	 * @param string $setId The set.
	 *
	 * @return JSONResponse 200 `{verified, setHash, items}`; 403 without case read access; 404 for another case's set.
	 *
	 * @spec openspec/changes/woo-delivered-set-is-a-record/specs/woo-delivered-set/spec.md#requirement-a-set-is-re-verifiable-req-wds-003
	 */
	#[NoAdminRequired]
	public function verify(string $id, string $setId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null || $this->guard->hasCaseReadAccess(caseId: $id, user: $user) === false) {
			return new JSONResponse(['error' => 'forbidden'], Http::STATUS_FORBIDDEN);
		}

		$set = $this->verifier->find(setId: $setId);
		if ($set === null || (string)($set['case'] ?? '') !== $id) {
			return new JSONResponse(['error' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($this->verifier->verify(set: $set));
	}//end verify()
}//end class
