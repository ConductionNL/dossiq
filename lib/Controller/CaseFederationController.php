<?php

/**
 * Dossiq Case Federation Controller
 *
 * Controller for cross-instance (federated) case collaboration: field-scoped
 * federated shares, their revocation, the shared activity stream, and remote
 * transfer accept/reject.
 *
 * Split out of CaseSharingController along the federation seam. That
 * controller keeps the single-instance surface — local token/partner shares
 * and local transfers — where the caller is always a local session. Every
 * endpoint here instead involves a remote Nextcloud instance, either as the
 * subject of the share or, on the `#[PublicPage]` paths, as the caller
 * authenticated by an OpenRegister federated-share bearer token rather than a
 * session.
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
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\CaseCollaborationService;
use OCA\Dossiq\Service\CaseSharingService;
use OCA\Dossiq\Service\CaseTransferService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Security\Bruteforce\IThrottler;
use Psr\Log\LoggerInterface;

/**
 * Controller for federated case shares and the shared activity stream.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */
class CaseFederationController extends Controller {

	/**
	 * Brute-force throttler action for rejected federation share tokens.
	 *
	 * One action across handleFederatedTransfer, postRemoteActivity and
	 * listRemoteActivity — they all authenticate the same way, so guesses must
	 * not be splittable across the three to stay under a per-endpoint ceiling.
	 *
	 * @var string
	 */
	private const THROTTLE_ACTION = 'dossiq_case_federation_share_token';

	/**
	 * Record a rejected share token with the brute-force throttler.
	 *
	 * These three endpoints are `#[PublicPage]` and the token in the URL is the
	 * only credential; a rejection means it did not resolve to a live share.
	 *
	 * This is the half that COUNTS. `#[BruteForceProtection]` on the endpoints
	 * is the half that ENFORCES — BruteForceMiddleware only applies a delay
	 * when the attribute is present, so registering without it writes a counter
	 * nothing reads. See ADR-082.
	 *
	 * @return void
	 */
	private function registerFailedShareToken(): void {
		try {
			$this->throttler->registerAttempt(
				action: self::THROTTLE_ACTION,
				ip: $this->request->getRemoteAddress()
			);
		} catch (\Throwable $throttlerFailure) {
			$this->logger->warning(
				'CaseFederationController: registerAttempt failed: ' . $throttlerFailure->getMessage()
			);
		}
	}//end registerFailedShareToken()

	/**
	 * Constructor for the CaseFederationController.
	 *
	 * @param IRequest $request The request object
	 * @param CaseSharingService $caseSharingService The sharing service
	 * @param CaseTransferService $caseTransferService The transfer service
	 * @param CaseCollaborationService $collabService The federated activity service
	 * @param IUserSession $userSession The user session
	 * @param IThrottler $throttler The brute-force throttler counting rejected share tokens
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		IRequest $request,
		private CaseSharingService $caseSharingService,
		private CaseTransferService $caseTransferService,
		private CaseCollaborationService $collabService,
		private IUserSession $userSession,
		private IThrottler $throttler,
		private LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * Create a federated case share: a field-scoped snapshot shared with a
	 * remote org over OpenRegister's OCM federation leaf.
	 *
	 * @NoAdminRequired
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
	 */
	public function createFederatedShare(): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$wanted = $this->requestedShare();
		$refusal = $this->shareRefusal(wanted: $wanted, userId: $user->getUID());
		if ($refusal !== null) {
			return $refusal;
		}

		$share = $this->caseSharingService->createFederatedShare(
			$wanted['caseId'],
			$wanted['remoteCloudId'],
			$wanted['sharedFields'],
			$wanted['sharedDocuments'],
			$wanted['permissionLevel'],
			$user->getUID(),
		);

		if (isset($share['error']) === true) {
			return new JSONResponse(['success' => false, 'error' => $share['error']], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['success' => true, 'share' => $share]);
	}//end createFederatedShare()

	/**
	 * The share the request asks for.
	 *
	 * @return array{caseId: mixed, remoteCloudId: mixed, sharedFields: array<mixed>, sharedDocuments: array<mixed>, permissionLevel: mixed}
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
	 */
	private function requestedShare(): array {
		return [
			'caseId' => $this->request->getParam('caseId'),
			'remoteCloudId' => $this->request->getParam('remoteCloudId'),
			'sharedFields' => (array)$this->request->getParam('sharedFields', []),
			'sharedDocuments' => (array)$this->request->getParam('sharedDocuments', []),
			'permissionLevel' => $this->request->getParam('permissionLevel', 'bekijken'),
		];
	}//end requestedShare()

	/**
	 * The refusal for a share without a case or remote, or on a case the caller may not access.
	 *
	 * @param array<string, mixed> $wanted The requested share.
	 * @param string               $userId The caller.
	 *
	 * @return JSONResponse|null The 400/403 refusal, or null when the share may be made.
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
	 */
	private function shareRefusal(array $wanted, string $userId): ?JSONResponse {
		if (empty($wanted['caseId']) === true || empty($wanted['remoteCloudId']) === true) {
			return new JSONResponse(['success' => false, 'error' => 'caseId and remoteCloudId are required'], 400);
		}

		// C2: same case-access guard as the partner/token share paths.
		if ($this->caseSharingService->canUserAccessCase($wanted['caseId'], $userId) === false) {
			return new JSONResponse(
				['success' => false, 'error' => 'Access denied: you are not assigned to this case'],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end shareRefusal()

	/**
	 * Revoke a federated case share.
	 *
	 * @param string $shareId The UUID of the federated share to revoke
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
	 */
	public function revokeFederatedShare(string $shareId): JSONResponse {
		$user = $this->shareCaller(shareId: $shareId);
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$result = $this->caseSharingService->revokeFederatedShare($shareId, $user->getUID());
		if (isset($result['error']) === true) {
			return new JSONResponse(['success' => false, 'error' => $result['error']], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['success' => true, 'share' => $result]);
	}//end revokeFederatedShare()

	/**
	 * Remote (cross-instance) transfer accept/reject, authenticated via the
	 * transfer-scoped OR federated share bearer token — NOT a local
	 * session. Public by design: the caller is another Nextcloud instance.
	 *
	 * @param string $shareToken The transfer-scoped bearer token
	 * @param string $transferId The transfer UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#a-remote-org-accepts-a-transfer-addressed-to-it-via-its-scoped-token
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function handleFederatedTransfer(string $shareToken, string $transferId): JSONResponse {
		$verified = $this->caseTransferService->resolveFederatedTransferShare($shareToken, $transferId);
		if ($verified === null) {
			$this->registerFailedShareToken();
			return new JSONResponse(['success' => false, 'error' => 'Invalid or unauthorized transfer token'], Http::STATUS_FORBIDDEN);
		}

		$action = $this->request->getParam('action');

		$result = match ($action) {
			'accept' => $this->caseTransferService->acceptTransfer($transferId, $verified['sharedWith']),
			'reject' => $this->caseTransferService->rejectTransfer(
				$transferId,
				$this->request->getParam('reason', ''),
				$verified['sharedWith']
			),
			default => null,
		};

		if ($result === null) {
			return new JSONResponse(['success' => false, 'error' => 'Action must be accept or reject'], 400);
		}

		if (isset($result['error']) === true) {
			return new JSONResponse(['success' => false, 'error' => $result['error']], Http::STATUS_CONFLICT);
		}

		return new JSONResponse(['success' => true, 'transfer' => $result]);
	}//end handleFederatedTransfer()

	/**
	 * Post a local activity entry on a federated case share's collaboration
	 * stream.
	 *
	 * @param string $federatedShareId The caseFederatedShare UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#a-local-handler-posts-an-activity-entry
	 */
	public function postActivity(string $federatedShareId): JSONResponse {
		$user = $this->shareCaller(shareId: $federatedShareId);
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$message = (string)$this->request->getParam('message', '');
		if ($message === '') {
			return new JSONResponse(['success' => false, 'error' => 'message is required'], 400);
		}

		$result = $this->collabService->postLocalActivity($federatedShareId, $user->getUID(), $message);
		if (isset($result['error']) === true) {
			return new JSONResponse(['success' => false, 'error' => $result['error']], Http::STATUS_BAD_GATEWAY);
		}

		return new JSONResponse(['success' => true, 'activity' => $result]);
	}//end postActivity()

	/**
	 * List the local view of a federated case share's activity stream.
	 *
	 * @param string $federatedShareId The caseFederatedShare UUID
	 *
	 * @return JSONResponse
	 *
	 * @NoAdminRequired
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#shared-activity-stream-is-async-append-only-scoped-to-one-federated-share
	 */
	public function listActivity(string $federatedShareId): JSONResponse {
		$user = $this->shareCaller(shareId: $federatedShareId);
		if ($user instanceof JSONResponse) {
			return $user;
		}

		$entries = $this->collabService->listActivity($federatedShareId);
		return new JSONResponse(['success' => true, 'entries' => $entries]);
	}//end listActivity()

	/**
	 * The signed-in caller of a federated share endpoint, or the refusal.
	 *
	 * Nobody signed in is 401. A caller who may not reach the case the share
	 * belongs to is 403; a share without a resolvable case is not checked
	 * against a case.
	 *
	 * @param string $shareId The federated share.
	 *
	 * @return IUser|JSONResponse The caller, or the refusal.
	 */
	private function shareCaller(string $shareId): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseId = $this->caseSharingService->getCaseIdForFederatedShare($shareId);
		if ($caseId !== null && $this->caseSharingService->canUserAccessCase($caseId, $user->getUID()) === false) {
			return new JSONResponse(
				['success' => false, 'error' => 'Access denied: you are not assigned to this case'],
				Http::STATUS_FORBIDDEN
			);
		}

		return $user;
	}//end shareCaller()

	/**
	 * Post a remote activity entry, authenticated via the federated share's
	 * scoped bearer token. Public by design: the caller is another
	 * Nextcloud instance.
	 *
	 * @param string $shareToken The scoped bearer token
	 * @param string $federatedShareId The caseFederatedShare UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#a-remote-org-posts-an-activity-entry-via-its-scoped-token
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 30, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function postRemoteActivity(string $shareToken, string $federatedShareId): JSONResponse {
		$message = (string)$this->request->getParam('message', '');
		if ($message === '') {
			return new JSONResponse(['success' => false, 'error' => 'message is required'], 400);
		}

		$result = $this->collabService->postRemoteActivity($shareToken, $federatedShareId, $message);
		if (isset($result['error']) === true) {
			$this->registerFailedShareToken();
			return new JSONResponse(['success' => false, 'error' => $result['error']], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['success' => true, 'activity' => $result]);
	}//end postRemoteActivity()

	/**
	 * List a federated case share's activity stream via a remote bearer
	 * token. Public by design: the caller is another Nextcloud instance.
	 *
	 * @param string $shareToken The scoped bearer token
	 * @param string $federatedShareId The caseFederatedShare UUID
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#shared-activity-stream-is-async-append-only-scoped-to-one-federated-share
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	#[BruteForceProtection(action: self::THROTTLE_ACTION)]
	public function listRemoteActivity(string $shareToken, string $federatedShareId): JSONResponse {
		$result = $this->collabService->listRemoteActivity($shareToken, $federatedShareId);
		if (isset($result['error']) === true) {
			$this->registerFailedShareToken();
			return new JSONResponse(['success' => false, 'error' => $result['error']], Http::STATUS_FORBIDDEN);
		}

		return new JSONResponse(['success' => true, 'entries' => ($result['entries'] ?? [])]);
	}//end listRemoteActivity()
}//end class
