<?php

/**
 * Dossiq Case Sharing Controller
 *
 * Controller for case share creation/revocation and case transfer workflow.
 *
 * Pure CRUD over the caseShare / partnerOrganization / casetransfer schemas
 * is handled by the OpenRegister manifest renderer; this controller only
 * owns the domain-specific endpoints (token generation, audit logging,
 * transfer accept/reject).
 *
 * Scope is the single-instance surface: every caller here is a local
 * session. The cross-instance surface — federated shares, the shared
 * activity stream and remote transfer accept/reject — lives on
 * {@see CaseFederationController}.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseSharingService;
use OCA\Dossiq\Service\CaseTransferService;
use OCA\Dossiq\Service\Sharing\CaseAccessLinkService;
use OCA\Dossiq\Service\Sharing\CaseLinkShares;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Controller for case share token actions and transfer workflow.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */
class CaseSharingController extends Controller {
	/**
	 * Constructor for the CaseSharingController.
	 *
	 * @param IRequest $request The request object
	 * @param CaseSharingService $caseSharingService The sharing service
	 * @param CaseTransferService $caseTransferService The transfer service
	 * @param IUserSession $userSession The user session
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private CaseSharingService $caseSharingService,
		private CaseTransferService $caseTransferService,
		private IUserSession $userSession,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Create a new case share (token or partner).
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function createShare(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId');
		$refusal = $this->shareRefusal(caseId: $caseId, user: $user);
		if ($refusal !== null) {
			return $refusal;
		}

		if ($this->request->getParam('shareType', 'token') === 'partner') {
			return $this->partnerShare(
				caseId: $caseId,
				permissionLevel: (string)$this->request->getParam('permissionLevel', 'bekijken'),
				createdBy: $user->getUID(),
			);
		}

		return $this->tokenShare(caseId: $caseId, user: $user);
	}//end createShare()

	/**
	 * Refuse a share by a caller not assigned to the case (403) or without a case (400).
	 *
	 * C2: the caller must have access to the case before a share is created;
	 * the access check runs first, as it always did.
	 *
	 * @param mixed $caseId The requested case.
	 * @param IUser $user   The caller.
	 *
	 * @return JSONResponse|null The refusal, or null when the share may be made.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function shareRefusal(mixed $caseId, IUser $user): ?JSONResponse {
		if (empty($caseId) === false && $this->caseSharingService->canUserAccessCase($caseId, $user->getUID()) === false) {
			return $this->notAssigned();
		}

		if (empty($caseId) === true) {
			return new JSONResponse(['success' => false, 'error' => 'caseId is required'], 400);
		}

		return null;
	}//end shareRefusal()

	/**
	 * Mint a public case link, or say why it was not minted.
	 *
	 * A public case link is an OpenRegister access link (#3817). It owns
	 * the anchor, the expiry, the password check and the revoke; dossiq
	 * stores none of them. The C2 owner/handler guard is the authz
	 * scope for publishing a case (ADR-005).
	 *
	 * @param mixed $caseId The case.
	 * @param IUser $user   The caller, who owns the link.
	 *
	 * @return JSONResponse The share and its url, or the refusal.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function tokenShare(mixed $caseId, IUser $user): JSONResponse {
		$share = $this->caseSharingService->createTokenShare(
			caseId: $caseId,
			label: (string)$this->request->getParam('label', ''),
			createdBy: $user->getUID(),
			expiresAt: $this->request->getParam('expiresAt'),
			capabilities: $this->asList(value: $this->request->getParam('capabilities'), fallback: CaseAccessLinkService::DEFAULT_CAPABILITIES),
			password: $this->optionalText(value: $this->request->getParam('password')),
			sharedDocuments: $this->asList(value: $this->request->getParam('sharedDocuments'), fallback: []),
		);

		if (isset($share['error']) === true) {
			return $this->refusedMint(share: $share);
		}

		return new JSONResponse(['success' => true, 'share' => $share['share'], 'url' => $share['url']]);
	}//end tokenShare()

	/**
	 * Share a case with a partner organisation, or say why it did not leave.
	 *
	 * @param string $caseId          The case.
	 * @param string $permissionLevel What the partner may do.
	 * @param string $createdBy       Who is sharing it.
	 *
	 * @return JSONResponse The share, or the refusal.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-partner-organisation-handover-is-out-of-scope-for-the-shares-leaf
	 */
	private function partnerShare(string $caseId, string $permissionLevel, string $createdBy): JSONResponse {
		$partnerId = $this->request->getParam('partnerId');
		if (empty($partnerId) === true) {
			return new JSONResponse(
				['success' => false, 'error' => 'partnerId is required for partner shares'],
				400
			);
		}

		$exclusions = $this->request->getParam('fieldExclusions', []);
		if (is_array($exclusions) === false) {
			$exclusions = [];
		}

		$partnerShare = $this->caseSharingService->createPartnerShare(
			caseId: $caseId,
			partnerId: $partnerId,
			permissionLevel: $permissionLevel,
			createdBy: $createdBy,
			message: (string)$this->request->getParam('message', ''),
			fieldExclusions: $exclusions,
		);

		if (isset($partnerShare['error']) === false) {
			return new JSONResponse(['success' => true, 'share' => $partnerShare]);
		}

		return $this->refusedPartnerShare(partnerShare: $partnerShare);
	}//end partnerShare()

	/**
	 * The answer when a partner share did not leave.
	 *
	 * A REFUSAL IS NOT AN UPSTREAM FAILURE. 502 says OpenRegister broke; a
	 * missing or lapsed consent is this instance deciding, correctly, that
	 * the case does not leave. The rule slug is carried so the caller can
	 * tell the two apart and say which of case, receiver or period was the
	 * one that did not match.
	 *
	 * @param array<string, mixed> $partnerShare What the sharing service answered.
	 *
	 * @return JSONResponse The 409 for a refusal, the 502 for an upstream failure.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-partner-organisation-handover-is-out-of-scope-for-the-shares-leaf
	 */
	private function refusedPartnerShare(array $partnerShare): JSONResponse {
		if (isset($partnerShare['rule']) === true && $partnerShare['rule'] !== '') {
			return new JSONResponse(
				['success' => false, 'error' => $partnerShare['error'], 'rule' => $partnerShare['rule']],
				Http::STATUS_CONFLICT
			);
		}

		return new JSONResponse(
			['success' => false, 'error' => $partnerShare['error']],
			Http::STATUS_BAD_GATEWAY
		);
	}//end refusedPartnerShare()

	/**
	 * The answer when a link share was not minted.
	 *
	 * A 502 says the app upstream broke. When this instance simply never
	 * mapped the case share schema, nothing upstream broke and naming
	 * OpenRegister sends the reader to the wrong app. 503 with the message the
	 * service wrote says what is missing and who fixes it.
	 *
	 * `linksNotRevoked` is carried when a link was minted, the record failed,
	 * and OpenRegister then refused to withdraw the link. A share reported as
	 * failed whose link still opens is the worst of the three outcomes, so the
	 * caller is told which ids are still live rather than left to find out.
	 *
	 * Extracted rather than written inline: `createShare()` already sat over
	 * phpmd's cyclomatic and NPath thresholds on `development`, and these two
	 * branches took it over the method-length threshold as well.
	 *
	 * @param array<string, mixed> $share What the sharing service answered.
	 *
	 * @return JSONResponse The refusal.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function refusedMint(array $share): JSONResponse {
		$status = Http::STATUS_BAD_GATEWAY;
		if (($share['reason'] ?? '') === CaseLinkShares::REASON_NOT_CONFIGURED) {
			$status = Http::STATUS_SERVICE_UNAVAILABLE;
		}

		$body = ['success' => false, 'error' => $share['error']];
		if (isset($share['linksNotRevoked']) === true) {
			$body['linksNotRevoked'] = $share['linksNotRevoked'];
		}

		return new JSONResponse($body, $status);
	}//end refusedMint()

	/**
	 * Read a request parameter that may arrive as a list or as a
	 * comma-separated string.
	 *
	 * @param mixed $value What the request carried
	 * @param array<int, string> $fallback What to use when it carried nothing
	 *
	 * @return array<int, string> The list
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function asList(mixed $value, array $fallback): array {
		if (is_array($value) === true) {
			return array_values(array_map(static fn ($entry): string => (string)$entry, $value));
		}

		$raw = trim((string)$value);
		if ($raw === '') {
			return $fallback;
		}

		return array_values(array_filter(array_map('trim', explode(',', $raw))));
	}//end asList()

	/**
	 * A request parameter as trimmed text, or null when it carried nothing.
	 *
	 * @param mixed $value What the request carried
	 *
	 * @return string|null The text, or null
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function optionalText(mixed $value): ?string {
		if ($value === null) {
			return null;
		}

		$text = trim((string)$value);
		if ($text === '') {
			return null;
		}

		return $text;
	}//end optionalText()

	/**
	 * Revoke a case share.
	 *
	 * @param string $shareId The UUID of the share to revoke
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function revokeShare(string $shareId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// Partner-organisation handover revoke (zaak-domain, in-app object).
		// C2: Resolve the share's caseId, then verify the caller has access to that case.
		// A share whose case cannot be resolved is refused like one without access.
		$caseId = $this->caseSharingService->getCaseIdForShare($shareId);
		if ($caseId === null
			|| $this->caseSharingService->canUserAccessCase($caseId, $user->getUID()) === false
		) {
			return new JSONResponse(
				['success' => false, 'error' => 'Access denied: you are not assigned to this case'],
				Http::STATUS_FORBIDDEN
			);
		}

		$share = $this->caseSharingService->revokeShare($shareId, $user->getUID());
		return new JSONResponse(['success' => true, 'share' => $share]);
	}//end revokeShare()

	/**
	 * Initiate a case transfer to another organization.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function initiateTransfer(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->request->getParam('caseId');
		if (empty($caseId) === true || empty($this->request->getParam('targetOrganization')) === true) {
			return new JSONResponse(
				['success' => false, 'error' => 'caseId and targetOrganization are required'],
				400
			);
		}

		// C2: Verify the caller has access to this case before allowing a transfer.
		if ($this->caseSharingService->canUserAccessCase($caseId, $user->getUID()) === false) {
			return $this->notAssigned();
		}

		return $this->startTransfer(caseId: $caseId, user: $user);
	}//end initiateTransfer()

	/**
	 * Start the transfer the request asks for, and answer with it.
	 *
	 * Federated (cross-instance) when a remote cloud id is supplied;
	 * local-only otherwise.
	 *
	 * @param mixed $caseId The case.
	 * @param IUser $user   The caller, who initiates it.
	 *
	 * @return JSONResponse The transfer, or the 502 when it could not start.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	private function startTransfer(mixed $caseId, IUser $user): JSONResponse {
		$transfer = $this->caseTransferService->initiateTransfer(
			$caseId,
			$this->request->getParam('sourceOrganization', ''),
			$this->request->getParam('targetOrganization'),
			$this->request->getParam('reason', ''),
			$this->request->getParam('requestedDate', date('Y-m-d')),
			$user->getUID(),
			$this->nonEmptyOrNull(value: $this->request->getParam('remoteCloudId')),
		);

		if (isset($transfer['error']) === true) {
			return new JSONResponse(['success' => false, 'error' => $transfer['error']], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['success' => true, 'transfer' => $transfer]);
	}//end startTransfer()

	/**
	 * A value, or null when it is empty.
	 *
	 * @param mixed $value The request value.
	 *
	 * @return mixed
	 */
	private function nonEmptyOrNull(mixed $value): mixed {
		if (empty($value) === true) {
			return null;
		}

		return $value;
	}//end nonEmptyOrNull()

	/**
	 * Handle a transfer request (accept or reject) — local session path.
	 *
	 * @param string $transferId The UUID of the transfer request
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function handleTransfer(string $transferId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		// C2 (pre-existing gap, fixed alongside the federation extension):
		// the caller must have access to the transfer's case before they
		// may accept/reject it — mirrors the guard initiateTransfer()
		// already had. Previously this endpoint had NO authorization check
		// at all: any authenticated user could accept/reject any transfer
		// by UUID.
		// A transfer whose case cannot be resolved is refused like one without access.
		$caseId = $this->caseTransferService->getCaseIdForTransfer($transferId);
		if ($caseId === null || $this->caseSharingService->canUserAccessCase($caseId, $user->getUID()) === false) {
			return $this->notAssigned();
		}

		return $this->transferAnswer(result: $this->decideTransfer(transferId: $transferId, user: $user));
	}//end handleTransfer()

	/**
	 * Accept or reject the transfer as the request's `action` says.
	 *
	 * @param string $transferId The transfer.
	 * @param IUser  $user       The caller, who decides it.
	 *
	 * @return array<string, mixed>|null The outcome, or null for any other action.
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	private function decideTransfer(string $transferId, IUser $user): ?array {
		return match ($this->request->getParam('action')) {
			'accept' => $this->caseTransferService->acceptTransfer(transferId: $transferId, actingUser: $user->getUID()),
			'reject' => $this->caseTransferService->rejectTransfer(
				transferId: $transferId,
				rejectionReason: $this->request->getParam('reason', ''),
				actingUser: $user->getUID(),
			),
			default => null,
		};
	}//end decideTransfer()

	/**
	 * The answer to a decided transfer: 400 for an unknown action, 409 when it could not be decided.
	 *
	 * @param array<string, mixed>|null $result The outcome.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	private function transferAnswer(?array $result): JSONResponse {
		if ($result === null) {
			return new JSONResponse(
				['success' => false, 'error' => 'Action must be accept or reject'],
				400
			);
		}

		if (isset($result['error']) === true) {
			return new JSONResponse(['success' => false, 'error' => $result['error']], Http::STATUS_CONFLICT);
		}

		return new JSONResponse(['success' => true, 'transfer' => $result]);
	}//end transferAnswer()

	/**
	 * The 403 for a caller not assigned to the case.
	 *
	 * @return JSONResponse
	 */
	private function notAssigned(): JSONResponse {
		return new JSONResponse(
			['success' => false, 'error' => 'Access denied: you are not assigned to this case'],
			Http::STATUS_FORBIDDEN
		);
	}//end notAssigned()
}//end class
