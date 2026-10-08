<?php

/**
 * Dossiq case access link controller.
 *
 * Everything a handler does to a link that is already minted: reading the
 * links on a case with the state of each, switching one off and back on,
 * seeing what its holder reads, and revoking it. Minting stays on
 * {@see CaseSharingController} beside the other two ways a case is shared,
 * because that is the choice between them.
 *
 * Every method is `@NoAdminRequired`, so the guard is the whole authorization
 * story, and it is two conditions rather than one: the caller may open the
 * case, AND the link is one that case minted. Dropping the second is the
 * cross-case IDOR of ADR-005 rule 3, where a handler of case A reaches case
 * B's link by quoting its id.
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
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseSharingService;
use OCA\Dossiq\Service\Sharing\CaseLinkShares;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Lists, pauses, previews and revokes the access links on a case.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md
 */
class CaseAccessLinkController extends Controller {
	/**
	 * Constructor.
	 *
	 * @param IRequest $request The request object
	 * @param CaseSharingService $caseSharingService Answers whether the caller may open the case
	 * @param CaseLinkShares $linkShares The share records the links are stored on
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private CaseSharingService $caseSharingService,
		private CaseLinkShares $linkShares,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The access links on a case, each with its state.
	 *
	 * @param string $caseId The case UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	public function index(string $caseId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->caseSharingService->canUserAccessCase($caseId, $user->getUID()) === false) {
			return new JSONResponse(
				['success' => false, 'error' => 'Access denied: you are not assigned to this case'],
				Http::STATUS_FORBIDDEN
			);
		}

		return new JSONResponse(['results' => $this->linkShares->listForCase(caseId: $caseId)]);
	}//end index()

	/**
	 * Switch a case link off, or back on.
	 *
	 * @param string $linkId The access link id, as a string from the route
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function pause(string $linkId): JSONResponse {
		$guard = $this->guard(linkId: $linkId);
		if ($guard instanceof JSONResponse) {
			return $guard;
		}

		$updated = $this->linkShares->pauseLink(
			linkId: (int)$linkId,
			userId: $guard,
			paused: (bool)$this->request->getParam('disabled', true)
		);

		if ($updated === null) {
			return new JSONResponse(
				['success' => false, 'error' => 'Could not change the link. Only the colleague who created it can.'],
				Http::STATUS_BAD_GATEWAY
			);
		}

		return new JSONResponse(['success' => true, 'link' => $updated]);
	}//end pause()

	/**
	 * What the holder of a case link reads.
	 *
	 * @param string $linkId The access link id, as a string from the route
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	public function preview(string $linkId): JSONResponse {
		$guard = $this->guard(linkId: $linkId);
		if ($guard instanceof JSONResponse) {
			return $guard;
		}

		$preview = $this->linkShares->holderPreview(
			linkId: (int)$linkId,
			caseId: (string)$this->request->getParam('caseId', '')
		);

		if ($preview === null) {
			return new JSONResponse(
				['success' => false, 'error' => 'Could not show what this link publishes'],
				Http::STATUS_NOT_FOUND
			);
		}

		return new JSONResponse(['success' => true, 'preview' => $preview]);
	}//end preview()

	/**
	 * Revoke a case link.
	 *
	 * OpenRegister revokes a link only for the colleague who minted it, so a
	 * refusal is reported rather than swallowed: a link reported revoked that
	 * still opens the case is the worst of the three possible outcomes.
	 *
	 * @param string $linkId The access link id, as a string from the route
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function revoke(string $linkId): JSONResponse {
		$guard = $this->guard(linkId: $linkId);
		if ($guard instanceof JSONResponse) {
			return $guard;
		}

		if ($this->linkShares->revokeLink(linkId: (int)$linkId, userId: $guard) === false) {
			return new JSONResponse(
				['success' => false, 'error' => 'Could not revoke the link. Only the colleague who created it can.'],
				Http::STATUS_BAD_GATEWAY
			);
		}

		return new JSONResponse(['success' => true]);
	}//end revoke()

	/**
	 * The guard every link action runs: a session, a case the caller may open,
	 * and a link that case actually minted.
	 *
	 * Returns the caller's user id when they may proceed, and the refusal to
	 * send otherwise.
	 *
	 * @param string $linkId The access link id from the route
	 *
	 * @return JSONResponse|string The refusal, or the caller's user id
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function guard(string $linkId): JSONResponse|string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = (string)$this->request->getParam('caseId', '');
		if ($caseId === '') {
			return new JSONResponse(['success' => false, 'error' => 'caseId is required'], Http::STATUS_BAD_REQUEST);
		}

		if ($this->caseSharingService->canUserAccessCase($caseId, $user->getUID()) === false
			|| $this->linkShares->belongsToCase((int)$linkId, $caseId) === false
		) {
			return new JSONResponse(
				['success' => false, 'error' => 'Access denied: you are not assigned to this case'],
				Http::STATUS_FORBIDDEN
			);
		}

		return $user->getUID();
	}//end guard()
}//end class
