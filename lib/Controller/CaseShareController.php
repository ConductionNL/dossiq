<?php

/**
 * Dossiq CaseShareController.
 *
 * `GET /api/cases/{caseId}/shares` lists every share of the case outside the
 * organisation; `DELETE /api/cases/{caseId}/shares/{shareId}` revokes one
 * through OpenRegister and answers a refusal in a card notice.
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
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-the-case-lists-its-shares-and-a-refused-revoke-is-a-card-notice
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\Notice\CaseActNotices;
use OCA\Dossiq\Service\Sharing\CaseLinkShares;
use OCA\Dossiq\Service\Sharing\CaseShareList;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The shares of one case.
 *
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-the-case-lists-its-shares-and-a-refused-revoke-is-a-card-notice
 */
class CaseShareController extends Controller {

	/**
	 * Constructor.
	 *
	 * @param IRequest        $request     The request.
	 * @param CaseAccessGuard $caseAccess  Whether the caller may read the case.
	 * @param CaseShareList   $shares      The share rows.
	 * @param CaseLinkShares  $linkShares  Whether a link is the case's, and revokes it.
	 * @param IUserSession    $userSession The caller.
	 * @param CaseActNotices  $notices     The refusal notice.
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseAccessGuard $caseAccess,
		private readonly CaseShareList $shares,
		private readonly CaseLinkShares $linkShares,
		private readonly IUserSession $userSession,
		private readonly CaseActNotices $notices,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Every share of the case.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return JSONResponse `{ results }`, or a refusal.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-the-case-lists-its-shares-and-a-refused-revoke-is-a-card-notice
	 */
	#[NoAdminRequired]
	public function index(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseAccess->hasCaseReadAccess(caseId: $caseId, user: $user) === false) {
			return new JSONResponse(['message' => 'You may not read this case.'], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['results' => $this->shares->rows(caseId: $caseId, uid: $user->getUID())]);
	}//end index()

	/**
	 * Revoke one share of the case through OpenRegister.
	 *
	 * The share must be one this case minted, so a share of another case
	 * cannot be reached by naming its id here.
	 *
	 * @param string $caseId  The case uuid.
	 * @param string $shareId The share id, as the list answers it.
	 *
	 * @return JSONResponse `{ revoked: true }`, or a refusal (409 with a notice when OpenRegister refuses).
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-the-case-lists-its-shares-and-a-refused-revoke-is-a-card-notice
	 */
	#[NoAdminRequired]
	public function destroy(string $caseId, string $shareId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$linkId = (int)$shareId;
		if ($this->caseAccess->hasCaseReadAccess(caseId: $caseId, user: $user) === false
			|| $this->linkShares->belongsToCase(linkId: $linkId, caseId: $caseId) === false
		) {
			return new JSONResponse(['message' => 'You may not revoke this share.'], Http::STATUS_FORBIDDEN);
		}

		if ($this->linkShares->revokeLink(linkId: $linkId, userId: $user->getUID()) === false) {
			$notice = $this->notices->revokeRefused();

			return new JSONResponse(
				['message' => $notice['title'] . ' ' . $notice['text'], 'error' => 'revoke_refused', 'notice' => $notice],
				Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse(['revoked' => true]);
	}//end destroy()
}//end class
