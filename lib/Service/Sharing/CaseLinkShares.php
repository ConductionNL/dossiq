<?php

/**
 * Dossiq case link shares.
 *
 * The `caseShare` records that point at OpenRegister access links: writing one
 * when a case is shared, listing them for the Sharing tab with the state of
 * each link, and answering the one question every link action asks first, which
 * is whether this link is one that case minted.
 *
 * Split out of CaseSharingService because that class was the seam between three
 * sharing modes and had become a fourth thing as well: the store for one of
 * them. The modes stay there, the record keeping lives here.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Sharing
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

namespace OCA\Dossiq\Service\Sharing;

use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Reads and writes the `caseShare` records that carry access links.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md
 */
class CaseLinkShares {

	/**
	 * The failure is this instance's own configuration, not OpenRegister.
	 *
	 * Carried on the error array so {@see \OCA\Dossiq\Controller\CaseSharingController}
	 * can answer 503 and name the gap, instead of 502 and a bare
	 * "Service unavailable" that points at an app which did nothing wrong.
	 *
	 * @var string
	 */
	public const REASON_NOT_CONFIGURED = 'not-configured';

	/**
	 * The share record could not be written, so the minted links were pulled.
	 *
	 * @var string
	 */
	public const REASON_ROLLED_BACK = 'rolled-back';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service.
	 * @param OpenRegisterSharingGateway $gateway Resolves the OpenRegister services.
	 * @param CaseAccessLinkService $accessLinks Mints, revokes and previews the links.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly OpenRegisterSharingGateway $gateway,
		private readonly CaseAccessLinkService $accessLinks,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Every access-link share on a case, each carrying the state of its link.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, mixed>> The shares.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	public function listForCase(string $caseId): array {
		$found = $this->readRecords(caseId: $caseId);

		$shares = [];
		foreach ($found as $candidate) {
			$share = $this->gateway->toArray($candidate);
			if ($share === []) {
				continue;
			}

			$share['state'] = $this->accessLinks->stateOf(link: $share);
			$shares[] = $share;
		}

		return $shares;
	}//end listForCase()

	/**
	 * Whether an access link is one this case minted.
	 *
	 * The IDOR guard in front of every revoke, pause and preview: a handler of
	 * case A must not reach case B's link by naming its id.
	 *
	 * @param int $linkId The OpenRegister access link id.
	 * @param string $caseId The candidate case UUID.
	 *
	 * @return bool True when the case carries a share holding that link.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function belongsToCase(int $linkId, string $caseId): bool {
		if ($linkId <= 0) {
			return false;
		}

		foreach ($this->listForCase(caseId: $caseId) as $share) {
			if ((int)($share['accessLinkId'] ?? 0) === $linkId) {
				return true;
			}
		}

		return false;
	}//end belongsToCase()

	/**
	 * Revoke the access link a share was minted as.
	 *
	 * The caller MUST have already authorised this against the owning case.
	 *
	 * @param int $linkId The OpenRegister access link id.
	 * @param string $userId The principal asking.
	 *
	 * @return bool True when OpenRegister revoked it.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function revokeLink(int $linkId, string $userId): bool {
		return $this->accessLinks->revokeLink(linkId: $linkId, userId: $userId);
	}//end revokeLink()

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
	public function pauseLink(int $linkId, string $userId, bool $paused): ?array {
		return $this->accessLinks->setPaused(linkId: $linkId, userId: $userId, paused: $paused);
	}//end pauseLink()

	/**
	 * What the holder of a case link reads.
	 *
	 * The anchor is read off the stored address rather than kept in a column of
	 * its own. The anchor IS the credential, and one copy of it in the register
	 * is one too many already.
	 *
	 * @param int $linkId The OpenRegister access link id.
	 * @param string $caseId The case the link is on.
	 *
	 * @return array<string, mixed>|null The body a holder is served, or null.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	public function holderPreview(int $linkId, string $caseId): ?array {
		foreach ($this->listForCase(caseId: $caseId) as $share) {
			if ((int)($share['accessLinkId'] ?? 0) !== $linkId) {
				continue;
			}

			$anchor = $this->anchorOf(url: (string)($share['accessLinkUrl'] ?? ''));
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
		$register = $this->settingsService->getConfigValue('register');
		$shareSchema = $this->settingsService->getConfigValue('case_share_schema');
		if ($objectService === null || empty($register) === true || empty($shareSchema) === true) {
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
				'CaseLinkShares: could not record the collected comment on the share',
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
	public function store(
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
			// 🔴 NAME WHAT IS UNAVAILABLE. This branch is reached when this
			// instance never finished configuring dossiq, which is a LOCAL
			// gap and not OpenRegister refusing anything. It used to answer
			// a bare "Service unavailable", which the controller then mapped
			// to 502. An e2e lane spent a batch on that 502 before the cause
			// turned out to be an unwritten app-config key. The `reason` is
			// what lets the controller answer 503 rather than 502.
			$this->logger->error(
				'CaseLinkShares: the case share schema is not configured, so no share can be recorded',
				['caseId' => $caseId, 'register' => $register, 'schema' => $schema]
			);
			return [
				'error' => 'Sharing is not configured on this instance yet. '
					. 'An administrator reloads the dossiq configuration to map the case share schema.',
				'reason' => self::REASON_NOT_CONFIGURED,
			];
		}

		$capabilities = (array)($link['capabilities'] ?? []);

		try {
			$result = $objectService->saveObject(
				object: $this->recordFor(
					caseId: $caseId,
					label: $label,
					createdBy: $createdBy,
					link: $link,
					capabilities: $capabilities,
					documents: $documents,
					extra: $extra
				),
				register: (int)$register,
				schema: (int)$schema,
			);
		} catch (\Throwable $e) {
			$this->logger->error(
				'CaseLinkShares: the link was minted and the share record was not written',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);
			return [
				'error' => 'The link could not be recorded on the case, so it was withdrawn again.',
				'reason' => self::REASON_ROLLED_BACK,
			];
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
	}//end store()

	/**
	 * Pull the links a failed mint already published.
	 *
	 * 🔴 THE LINK IS LIVE BEFORE THE RECORD EXISTS. `createTokenShare()` mints
	 * the case link first and writes the share record second, so every way the
	 * write can fail leaves a published access link on the case that dossiq
	 * has no row for. Nobody can see it in the sharing tab, and nobody can
	 * revoke it there, because revoking reads the record. The caller is told
	 * the share failed, which is true and is the opposite of what happened to
	 * the case.
	 *
	 * So the mint is undone. A link that cannot be recorded must not stay
	 * open.
	 *
	 * @param array<string, mixed> $link The minted case link.
	 * @param array<int, array<string, mixed>> $documents The file links minted beside it.
	 * @param string $userId The principal who minted them.
	 *
	 * @return array<int, int> The link ids OpenRegister refused to revoke.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function revokeMinted(array $link, array $documents, string $userId): array {
		return $this->revokeLinksOf(
			share: [
				'accessLinkId' => (int)($link['id'] ?? 0),
				'sharedDocuments' => $documents,
			],
			userId: $userId
		);
	}//end revokeMinted()

	/**
	 * Revoke every access link a share was minted as, and name the ones
	 * OpenRegister refused.
	 *
	 * OpenRegister revokes a link only for the colleague who minted it. A
	 * refusal is carried back to the caller rather than logged and forgotten,
	 * because a share marked revoked whose link still opens is the worst of the
	 * three possible outcomes.
	 *
	 * @param array<string, mixed> $share The share record.
	 * @param string $userId The principal asking.
	 *
	 * @return array<int, int> The link ids that were not revoked.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-document-named-on-the-share-gets-its-own-file-link-req-cal-02
	 */

	public function revokeLinksOf(array $share, string $userId): array {
		$refused = [];
		foreach ($this->linkIdsOf(share: $share) as $id) {
			if ($this->accessLinks->revokeLink(linkId: $id, userId: $userId) === false) {
				$refused[] = $id;
			}
		}

		return $refused;
	}//end revokeLinksOf()

	/**
	 * Every link id a share holds: the case link, then each file link.
	 *
	 * @param array<string, mixed> $share The share record.
	 *
	 * @return array<int, int> The link ids.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-document-named-on-the-share-gets-its-own-file-link-req-cal-02
	 */
	private function linkIdsOf(array $share): array {
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

		return $ids;
	}//end linkIdsOf()

	/**
	 * The `caseShare` records of one case, as OpenRegister returns them.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, mixed> The records.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	private function readRecords(string $caseId): array {
		$objectService = $this->gateway->objectService();
		$register = $this->settingsService->getConfigValue('register');
		$shareSchema = $this->settingsService->getConfigValue('case_share_schema');
		if ($objectService === null || empty($register) === true || empty($shareSchema) === true) {
			return [];
		}

		try {
			return (array)$objectService->findAll(
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
				'CaseLinkShares: could not list the case access links',
				['caseId' => $caseId, 'exception' => $e->getMessage()]
			);
			return [];
		}
	}//end readRecords()

	/**
	 * The share record to write for one minted link.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $label What the share is called.
	 * @param string $createdBy Who minted it.
	 * @param array<string, mixed> $link The minted link.
	 * @param array<int, string> $capabilities What the link grants.
	 * @param array<int, array<string, mixed>> $documents The file links minted beside it.
	 * @param array<string, mixed> $extra Extra fields to record.
	 *
	 * @return array<string, mixed> The record.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function recordFor(
		string $caseId,
		string $label,
		string $createdBy,
		array $link,
		array $capabilities,
		array $documents,
		array $extra,
	): array {
		// The permission level is the zaak-domain word for the same grant, kept
		// because the partner surface and the stored rows already speak it.
		$permissionLevel = 'bekijken';
		if (in_array('comment', $capabilities, true) === true) {
			$permissionLevel = 'bekijken_reageren';
		}

		return array_merge(
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
	}//end recordFor()

	/**
	 * The anchor at the end of a link address.
	 *
	 * @param string $url The stored address.
	 *
	 * @return string The anchor, or an empty string.
	 *
	 * @spec openspec/changes/case-sharing-mints-access-links/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	private function anchorOf(string $url): string {
		$url = trim($url);
		if ($url === '') {
			return '';
		}

		$tail = strrchr('/' . $url, '/');
		if ($tail === false) {
			return '';
		}

		return (string)substr($tail, 1);
	}//end anchorOf()
}//end class
