<?php

/**
 * Dossiq case reviewer access controller
 *
 * POST /api/cases/reviewer-access/reconcile (case-reviewer-access,
 * REQ-CRA-004): an administrator reconciles the private records of every
 * case whose case type declares reviewer access, once after an upgrade. Until
 * it has run, an existing private record is readable by its author and
 * administrators only, which is the safe state.
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
use OCA\Dossiq\Service\Access\CaseReviewerAccess;
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
	 * @param IRequest           $request      The request.
	 * @param CaseReviewerAccess $access       The reconciliation.
	 * @param IUserSession       $userSession  The caller.
	 * @param IGroupManager      $groupManager Whether the caller is an administrator.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseReviewerAccess $access,
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
	 * @return JSONResponse `{status, cases, granted, revoked, refused, failed}`; 403 for a non-administrator,
	 *                      503 with `unavailable` when OpenRegister has no per-object sharing.
	 *
	 * @spec openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md#requirement-existing-records-are-reconciled-by-an-administrator-req-cra-004
	 */
	#[AuthorizedAdminSetting(settings: AdminSettings::class)]
	public function reconcile(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null || $this->groupManager->isAdmin($user->getUID()) === false) {
			return new JSONResponse(['error' => 'administrator-only'], Http::STATUS_FORBIDDEN);
		}

		$answer = $this->access->reconcileAll();
		if ($answer['status'] === 'unavailable') {
			return new JSONResponse($answer, Http::STATUS_SERVICE_UNAVAILABLE);
		}

		return new JSONResponse($answer);
	}//end reconcile()
}//end class
