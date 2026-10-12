<?php

/**
 * Dossiq case reviewer access controller
 *
 * POST /api/cases/reviewer-access/reconcile (case-reviewer-access,
 * REQ-CRA-004): an administrator reconciles the private records of every
 * case whose case type declares reviewer access. The upgrade runs the same
 * walk (REQ-CRA-012), so this route is for the upgrade that said it could not
 * finish, and for a repair by hand.
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
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-by-an-administrator-req-cra-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Access\CaseRecordGrantsBackfill;
use OCA\Dossiq\Settings\AdminSettings;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Runs the reviewer access reconciliation over every declaring case.
 *
 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-by-an-administrator-req-cra-004
 */
class CaseReviewerAccessController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest                 $request      The request.
	 * @param CaseRecordGrantsBackfill $backfill     The walk over every declaring case.
	 * @param IUserSession             $userSession  The caller.
	 * @param IGroupManager            $groupManager Whether the caller is an administrator.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseRecordGrantsBackfill $backfill,
		private readonly IUserSession $userSession,
		private readonly IGroupManager $groupManager,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Reconcile every case of every case type that declares reviewer access.
	 *
	 * The admin-setting attribute lets the request through; the explicit
	 * administrator check here fails closed, because the grants it writes are
	 * the ones only an administrator may write on another user's record.
	 *
	 * @return JSONResponse `{status, actor, cases, granted, revoked, refused, failed, unfinished}`; 403 for a
	 *                      non-administrator, 503 with `unavailable` when OpenRegister has no per-object
	 *                      sharing. `status` is `incomplete`, never `reconciled`, when a case is in `failed`
	 *                      or a case type in `unfinished`.
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-by-an-administrator-req-cra-004
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function reconcile(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(['error' => 'administrator-only'], Http::STATUS_FORBIDDEN);
		}

		$answer = $this->backfill->run();
		if ($answer['status'] === 'unavailable') {
			return new JSONResponse($answer, Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($answer);
	}//end reconcile()
}//end class
