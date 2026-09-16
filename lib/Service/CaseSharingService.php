<?php

/**
 * Dossiq Case Sharing Service
 *
 * Service for managing case shares, token generation, and permission enforcement.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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

namespace OCA\Dossiq\Service;

use DateTime;
use OCA\Dossiq\Service\Sharing\CaseAccessLinkService;
use OCA\Dossiq\Service\Sharing\CaseAccessPolicy;
use OCA\Dossiq\Service\Sharing\FederatedCaseShareService;
use OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway;
use Psr\Log\LoggerInterface;

/**
 * Entry point for case sharing, and the owner of the in-app partner hand-off.
 *
 * Dossiq shares a case in three distinct ways, each with its own trust model,
 * and this class is the seam between them:
 *
 *  - a PUBLIC access link, delegated to {@see CaseAccessLinkService}, which
 *    mints nothing itself: OpenRegister owns the anchor, the expiry, the
 *    password and the revoke (openregister#3817);
 *  - a PARTNER-organisation hand-off, owned here, because org-to-org case
 *    hand-off inside one instance is zaak-domain logic and carries no public
 *    token (ADR-022);
 *  - a FEDERATED (OCM) share, delegated to {@see FederatedCaseShareService},
 *    which crosses an org boundary and therefore shares a redacted snapshot
 *    rather than the live case.
 *
 * Access decisions for all three live in {@see CaseAccessPolicy}, and every
 * reach into OpenRegister goes through {@see OpenRegisterSharingGateway}.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md
 */
class CaseSharingService {
	/**
	 * Hard-coded allow-list of case-summary fields that may ever cross a
	 * federation boundary. A field NOT in this list is rejected outright by
	 * {@see createFederatedShare()} — never silently dropped. `@self` and
	 * `relations` are deliberately never included: the fleet lesson is that
	 * a relations mirror can leak writeOnly fields, so it is excluded by
	 * construction rather than filtered after the fact.
	 *
	 * This constant stays on CaseSharingService: it is the documented source
	 * of truth that `src/utils/federatedShareHelpers.js` mirrors by name.
	 *
	 * @var string[]
	 */
	public const FEDERATION_ALLOWED_FIELDS = [
		'title',
		'description',
		'status',
		'caseType',
		'priority',
		'dueDate',
		'requestedDate',
	];

	/**
	 * Constructor for the CaseSharingService.
	 *
	 * @param SettingsService $settingsService The settings service
	 * @param OpenRegisterSharingGateway $gateway OpenRegister resolution for the sharing surface
	 * @param CaseAccessPolicy $accessPolicy Per-case access decisions
	 * @param CaseAccessLinkService $accessLinks Public access links over the case
	 * @param FederatedCaseShareService $federatedShares Cross-org (OCM) case shares
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private SettingsService $settingsService,
		private OpenRegisterSharingGateway $gateway,
		private CaseAccessPolicy $accessPolicy,
		private CaseAccessLinkService $accessLinks,
		private FederatedCaseShareService $federatedShares,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Check whether a given user may access a case for sharing purposes.
	 *
	 * @param string $caseId The case UUID
	 * @param string $userId The caller's user ID
	 *
	 * @return bool True when the user may proceed
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function canUserAccessCase(string $caseId, string $userId): bool {
		return $this->accessPolicy->canUserAccessCase(caseId: $caseId, userId: $userId);
	}//end canUserAccessCase()

	/**
	 * Share a case with somebody who has no account, by minting an
	 * OpenRegister access link over it.
	 *
	 * Each document named on the share mints its own `file` link, so an
	 * outsider who needs one report does not receive the dossier.
	 *
	 * @param string $caseId The UUID of the case to share
	 * @param string $label Human-readable label for the link
	 * @param string $createdBy User ID of the creator
	 * @param string|null $expiresAt ISO 8601 date the share stops opening
	 * @param array<int, string> $capabilities What the holder may do
	 * @param string|null $password An optional password, checked at use
	 * @param array<int, string> $sharedDocuments File ids to share beside the case
	 * @param array<string, mixed> $extra Extra fields to record on the share
	 *
	 * @return array The stored share plus the link, or an error array.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function createTokenShare(
		string $caseId,
		string $label,
		string $createdBy,
		?string $expiresAt = null,
		array $capabilities = CaseAccessLinkService::DEFAULT_CAPABILITIES,
		?string $password = null,
		array $sharedDocuments = [],
		array $extra = [],
	): array {
		$named = null;
		if ($label !== '') {
			$named = $label;
		}

		$link = $this->accessLinks->mintCaseLink(
			caseId: $caseId,
			userId: $createdBy,
			capabilities: $capabilities,
			expiresAt: $expiresAt,
			password: $password,
			label: $named
		);

		if (isset($link['error']) === true) {
			return $link;
		}

		$documents = [];
		foreach ($sharedDocuments as $fileId) {
			$fileLink = $this->accessLinks->mintFileLink(
				caseId: $caseId,
				fileId: (string)$fileId,
				userId: $createdBy,
				expiresAt: ($link['expiresAt'] ?? $expiresAt),
				password: $password,
				label: $named
			);

			if (isset($fileLink['error']) === true) {
				$this->logger->warning(
					'Dossiq: a document named on a case share did not get a link',
					['caseId' => $caseId, 'fileId' => $fileId, 'error' => $fileLink['error']]
				);
				continue;
			}

			$documents[] = [
				'fileId' => (string)$fileId,
				'linkId' => ($fileLink['id'] ?? null),
				'linkUuid' => ($fileLink['uuid'] ?? null),
				'url' => ($fileLink['url'] ?? null),
			];
		}//end foreach

		$stored = $this->storeShare(
			caseId: $caseId,
			label: $label,
			createdBy: $createdBy,
			link: $link,
			documents: $documents,
			extra: $extra
		);

		if (isset($stored['error']) === true) {
			return $stored;
		}

		return ['share' => $stored, 'link' => $link, 'url' => ($link['url'] ?? '')];
	}//end createTokenShare()

	/**
	 * Whether an access link is one this case minted.
	 *
	 * The IDOR guard in front of every revoke, pause and preview: a handler
	 * of case A must not reach case B's link by naming its id.
	 *
	 * @param int $linkId The OpenRegister access link id.
	 * @param string $caseId The candidate case UUID.
	 *
	 * @return bool True when the case carries a share holding that link.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function linkBelongsToCase(int $linkId, string $caseId): bool {
		foreach ($this->listLinkShares(caseId: $caseId) as $share) {
			if ((int)($share['accessLinkId'] ?? 0) === $linkId && $linkId > 0) {
				return true;
			}
		}

		return false;
	}//end linkBelongsToCase()

	/**
	 * Every access-link share on a case, each carrying the state of its link.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, mixed>> The shares.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	public function listLinkShares(string $caseId): array {
		$objectService = $this->gateway->objectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$shareSchema = $this->settingsService->getConfigValue('case_share_schema');
		if (empty($register) === true || empty($shareSchema) === true) {
			return [];
		}

		try {
			$found = $objectService->findAll(
				[
					'filters' => [
						'register' => (int)$register,
						'schema' => (int)$shareSchema,
						'caseId' => $caseId,
						'shareType' => 'link',
					],
				]
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'CaseSharingService: could not list the case access links',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);
			return [];
		}

		$shares = [];
		foreach ((array)$found as $candidate) {
			$share = $this->gateway->toArray($candidate);
			if ($share === []) {
				continue;
			}

			$share['state'] = $this->accessLinks->stateOf(link: $share);
			$shares[] = $share;
		}

		return $shares;
	}//end listLinkShares()

	/**
	 * Revoke the access link a share was minted as.
	 *
	 * The caller MUST have already authorised this against the owning case
	 * (see {@see linkBelongsToCase()} and {@see canUserAccessCase()}).
	 *
	 * @param int $linkId The OpenRegister access link id.
	 * @param string $userId The principal asking.
	 *
	 * @return bool True when OpenRegister revoked it.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function revokeTokenShare(int $linkId, string $userId): bool {
		return $this->accessLinks->revokeLink(linkId: $linkId, userId: $userId);
	}//end revokeTokenShare()

	/**
	 * Switch a share's link off, or back on.
	 *
	 * @param int $linkId The OpenRegister access link id.
	 * @param string $userId The principal asking.
	 * @param bool $paused True to switch it off.
	 *
	 * @return array<string, mixed>|null The updated link, or null.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function pauseTokenShare(int $linkId, string $userId, bool $paused): ?array {
		return $this->accessLinks->setPaused(linkId: $linkId, userId: $userId, paused: $paused);
	}//end pauseTokenShare()

	/**
	 * What the holder of a case link reads.
	 *
	 * The anchor is read off the stored address rather than kept in a column
	 * of its own. The anchor IS the credential, and one copy of it in the
	 * register is one too many already.
	 *
	 * @param int $linkId The OpenRegister access link id.
	 * @param string $caseId The case the link is on.
	 *
	 * @return array<string, mixed>|null The body a holder is served, or null.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	public function holderPreview(int $linkId, string $caseId): ?array {
		foreach ($this->listLinkShares(caseId: $caseId) as $share) {
			if ((int)($share['accessLinkId'] ?? 0) !== $linkId) {
				continue;
			}

			$url = trim((string)($share['accessLinkUrl'] ?? ''));
			if ($url === '') {
				return null;
			}

			$anchor = (string)substr(strrchr('/' . $url, '/'), 1);
			if ($anchor === '') {
				return null;
			}

			return $this->accessLinks->holderPreview(anchor: $anchor);
		}

		return null;
	}//end holderPreview()

	/**
	 * Record on a share which comment was the last one collected from it.
	 *
	 * Without this a second collection records the same advice again, and the
	 * consultation ends up carrying one answer twice.
	 *
	 * @param string $shareId The share UUID.
	 * @param int $noteId The id of the newest comment already collected.
	 *
	 * @return bool True when the share was updated.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
	 */
	public function markCollected(string $shareId, int $noteId): bool {
		$objectService = $this->gateway->objectService();
		if ($objectService === null) {
			return false;
		}

		$register = $this->settingsService->getConfigValue('register');
		$shareSchema = $this->settingsService->getConfigValue('case_share_schema');
		if (empty($register) === true || empty($shareSchema) === true) {
			return false;
		}

		try {
			$share = $this->gateway->toArray(
				$objectService->find($shareId, register: (int)$register, schema: (int)$shareSchema)
			);
			if ($share === []) {
				return false;
			}

			$share['lastCollectedNote'] = $noteId;
			$objectService->saveObject(object: $share, register: (int)$register, schema: (int)$shareSchema);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CaseSharingService: could not record the collected comment on the share',
				['shareId' => $shareId, 'exception' => $e->getMessage()]
			);
			return false;
		}

		return true;
	}//end markCollected()

	/**
	 * Write the share record that points at a minted link.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $label What the share is called.
	 * @param string $createdBy Who minted it.
	 * @param array<string, mixed> $link The minted link.
	 * @param array<int, array<string, mixed>> $documents The file links minted beside it.
	 * @param array<string, mixed> $extra Extra fields to record.
	 *
	 * @return array<string, mixed> The stored share, or an error array.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function storeShare(
		string $caseId,
		string $label,
		string $createdBy,
		array $link,
		array $documents,
		array $extra,
	): array {
		$objectService = $this->gateway->objectService();
		if ($objectService === null) {
			return ['error' => 'OpenRegister is not available'];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_share_schema');
		if (empty($register) === true || empty($schema) === true) {
			return ['error' => 'Service unavailable'];
		}

		$capabilities = (array)($link['capabilities'] ?? []);

		// The permission level is the zaak-domain word for the same grant, kept
		// because the partner surface and the stored rows already speak it.
		$permissionLevel = 'bekijken';
		if (in_array('comment', $capabilities, true) === true) {
			$permissionLevel = 'bekijken_reageren';
		}

		$shareData = array_merge(
			$extra,
			[
				'caseId' => $caseId,
				'shareType' => 'link',
				'permissionLevel' => $permissionLevel,
				'label' => $label,
				'status' => 'active',
				'createdBy' => $createdBy,
				'accessLinkId' => (int)($link['id'] ?? 0),
				'accessLinkUuid' => (string)($link['uuid'] ?? ''),
				'accessLinkUrl' => (string)($link['url'] ?? ''),
				'capabilities' => implode(',', $capabilities),
				'expiresAt' => (string)($link['expiresAt'] ?? ''),
				'sharedDocuments' => json_encode($documents),
			]
		);

		try {
			$result = $objectService->saveObject(
				object: $shareData,
				register: (int)$register,
				schema: (int)$schema,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CaseSharingService: the link was minted and the share record was not written',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);
			return ['error' => 'Could not record the share'];
		}

		$this->logger->info(
			'Dossiq: case access link minted',
			[
				'caseId' => $caseId,
				'createdBy' => $createdBy,
				'capabilities' => $capabilities,
				'documents' => count($documents),
			]
		);

		return $this->gateway->toArray($result);
	}//end storeShare()

	/**
	 * Create a partner organization-based case share.
	 *
	 * @param string $caseId The UUID of the case to share
	 * @param string $partnerId The UUID of the partner organization
	 * @param string $permissionLevel The permission level slug
	 * @param string $createdBy User ID of the creator
	 *
	 * @return array The created share data
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function createPartnerShare(
		string $caseId,
		string $partnerId,
		string $permissionLevel,
		string $createdBy,
	): array {
		$objectService = $this->gateway->objectService();
		if ($objectService === null) {
			return ['error' => 'OpenRegister is not available'];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('case_share_schema');

		// Partner-organisation handover is zaak-domain logic (org-to-org case
		// hand-off), NOT public token sharing — it stays in-app per ADR-022.
		// It carries no public token: the bespoke token mechanism moved to the
		// OR shares leaf (createTokenShare) and is the only public surface.
		$shareData = [
			'caseId' => $caseId,
			'shareType' => 'partner',
			'partnerId' => $partnerId,
			'permissionLevel' => $permissionLevel,
			'createdBy' => $createdBy,
		];

		$result = $objectService->saveObject(
			object: $shareData,
			register: (int)$register,
			schema: (int)$schema,
		);

		$this->logger->info(
			'Dossiq: Partner share created',
			[
				'caseId' => $caseId,
				'partnerId' => $partnerId,
				'shareId' => $result->getUuid(),
			]
		);

		return $result->jsonSerialize();
	}//end createPartnerShare()

	/**
	 * Look up the caseId for a given share UUID.
	 *
	 * Used by the controller to perform the per-case RBAC check before revocation.
	 * Returns null if the share cannot be found or OR is unavailable.
	 *
	 * @param string $shareId The share UUID
	 *
	 * @return string|null The caseId, or null when unavailable
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function getCaseIdForShare(string $shareId): ?string {
		$objectService = $this->gateway->objectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$shareSchema = $this->settingsService->getConfigValue('case_share_schema');

		if (empty($register) === true || empty($shareSchema) === true) {
			return null;
		}

		try {
			$shareObj = $objectService->find($shareId, register: (int)$register, schema: (int)$shareSchema);
			if ($shareObj === null) {
				return null;
			}

			$shareData = $shareObj;
			if (is_array($shareObj) === false) {
				$shareData = $shareObj->jsonSerialize();
			}

			if (isset($shareData['caseId']) === true) {
				return (string)$shareData['caseId'];
			}

			return null;
		} catch (\Throwable $e) {
			$this->logger->debug(
				'CaseSharingService: getCaseIdForShare failed',
				['shareId' => $shareId, 'exception' => $e->getMessage()]
			);
			return null;
		}//end try
	}//end getCaseIdForShare()

	/**
	 * Revoke a case share by marking it as revoked in OpenRegister.
	 *
	 * @param string $shareId The UUID of the share to revoke
	 * @param string $userId The user ID performing the revocation
	 *
	 * @return array The updated share data
	 *
	 * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
	 */
	public function revokeShare(string $shareId, string $userId): array {
		$objectService = $this->gateway->objectService();
		if ($objectService === null) {
			return ['error' => 'OpenRegister is not available'];
		}

		$register = $this->settingsService->getConfigValue('register');
		$shareSchema = $this->settingsService->getConfigValue('case_share_schema');

		if (empty($register) === true || empty($shareSchema) === true) {
			return ['error' => 'Service unavailable'];
		}

		$shareObj = $objectService->find($shareId, register: (int)$register, schema: (int)$shareSchema);
		if ($shareObj === null) {
			return ['error' => 'Share not found'];
		}

		$shareData = $shareObj;
		if (is_array($shareObj) === false) {
			$shareData = $shareObj->jsonSerialize();
		}

		$refused = $this->revokeLinksOf(share: $shareData, userId: $userId);

		$shareData['status'] = 'revoked';
		$shareData['revokedBy'] = $userId;
		$shareData['revokedAt'] = (new DateTime())->format('c');

		$result = $objectService->saveObject(object: $shareData, register: (int)$register, schema: (int)$shareSchema);

		$this->logger->info(
			'Dossiq: Case share revoked',
			['shareId' => $shareId, 'revokedBy' => $userId]
		);

		$revoked = $this->gateway->toArray($result);
		if ($refused !== []) {
			$revoked['linksNotRevoked'] = $refused;
		}

		return $revoked;
	}//end revokeShare()

	/**
	 * Revoke every access link a share was minted as, and name the ones
	 * OpenRegister refused.
	 *
	 * OpenRegister revokes a link only for the colleague who minted it. A
	 * refusal is carried back to the caller rather than logged and forgotten,
	 * because a share marked revoked whose link still opens is the worst of
	 * the three possible outcomes.
	 *
	 * @param array<string, mixed> $share The share record.
	 * @param string $userId The principal asking.
	 *
	 * @return array<int, int> The link ids that were not revoked.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-document-named-on-the-share-gets-its-own-file-link-req-cal-02
	 */
	private function revokeLinksOf(array $share, string $userId): array {
		$ids = [];
		$caseLinkId = (int)($share['accessLinkId'] ?? 0);
		if ($caseLinkId > 0) {
			$ids[] = $caseLinkId;
		}

		$documents = ($share['sharedDocuments'] ?? '');
		if (is_string($documents) === true) {
			$documents = json_decode($documents, true);
		}

		foreach ((array)$documents as $document) {
			if (is_array($document) === false) {
				continue;
			}

			$fileLinkId = (int)($document['linkId'] ?? 0);
			if ($fileLinkId > 0) {
				$ids[] = $fileLinkId;
			}
		}

		$refused = [];
		foreach ($ids as $id) {
			if ($this->accessLinks->revokeLink(linkId: $id, userId: $userId) === false) {
				$refused[] = $id;
			}
		}

		return $refused;
	}//end revokeLinksOf()

	/**
	 * Create a federated case share: a purpose-built, field-scoped snapshot
	 * of the case shared with a remote org over OpenRegister's OCM
	 * federation leaf.
	 *
	 * @param string $caseId The UUID of the case to share
	 * @param string $remoteCloudId The federated target (slug@host)
	 * @param array<string> $sharedFields Requested case field names
	 * @param array<string> $sharedDocuments Requested document references
	 * @param string $permissionLevel Permission level slug (informational; the OR grant is always 'read')
	 * @param string $createdBy User ID of the share creator
	 *
	 * @return array The created federated share data, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
	 */
	public function createFederatedShare(
		string $caseId,
		string $remoteCloudId,
		array $sharedFields,
		array $sharedDocuments,
		string $permissionLevel,
		string $createdBy,
	): array {
		return $this->federatedShares->createFederatedShare(
			caseId: $caseId,
			remoteCloudId: $remoteCloudId,
			sharedFields: $sharedFields,
			sharedDocuments: $sharedDocuments,
			permissionLevel: $permissionLevel,
			createdBy: $createdBy
		);
	}//end createFederatedShare()

	/**
	 * Revoke a federated case share.
	 *
	 * @param string $shareId The UUID of the caseFederatedShare to revoke
	 * @param string $userId The user ID performing the revocation
	 *
	 * @return array The updated share data, or an error array
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
	 */
	public function revokeFederatedShare(string $shareId, string $userId): array {
		return $this->federatedShares->revokeFederatedShare(shareId: $shareId, userId: $userId);
	}//end revokeFederatedShare()

	/**
	 * Look up the caseId for a given federated share UUID (for the
	 * controller's per-case RBAC check before revocation).
	 *
	 * @param string $shareId The federated share UUID
	 *
	 * @return string|null The caseId, or null when unavailable/not found
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
	 */
	public function getCaseIdForFederatedShare(string $shareId): ?string {
		return $this->federatedShares->getCaseIdForFederatedShare(shareId: $shareId);
	}//end getCaseIdForFederatedShare()
}//end class
