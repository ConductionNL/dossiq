<?php

/**
 * Dossiq CaseShareList.
 *
 * Every share of a case outside the organisation as one list for the share
 * list card: `{ id, kind, title, meta, status, url, link, revocable }`. The
 * rows are the access-link shares the case minted (CaseLinkShares); a share
 * that carries documents is a `file` share, else a `case` share.
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
 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-share-via-shares-leaf/spec.md#requirement-the-case-lists-its-shares-and-a-refused-revoke-is-a-card-notice
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Sharing;

use OCP\IL10N;

/**
 * Lists the shares of a case in the share list shape.
 *
 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-share-via-shares-leaf/spec.md#requirement-the-case-lists-its-shares-and-a-refused-revoke-is-a-card-notice
 */
class CaseShareList {

	/**
	 * The link state (CaseAccessLinkService::stateOf) as the share list names it.
	 *
	 * @var array<string, string>
	 */
	private const STATUS = [
		'live' => 'active',
		'paused' => 'paused',
		'expired' => 'expired',
		'revoked' => 'revoked',
	];

	/**
	 * Constructor.
	 *
	 * @param CaseLinkShares $linkShares The access-link shares of a case.
	 * @param IL10N          $l10n       Translates the row texts.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseLinkShares $linkShares,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The shares of a case, for this reader.
	 *
	 * A share is revocable by the reader when it is not revoked or expired
	 * and the reader created it, because OpenRegister revokes a link only for
	 * the colleague who minted it.
	 *
	 * @param string $caseId The case uuid.
	 * @param string $uid    The reader.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @spec openspec/changes/case-page-answers-in-card-notices/specs/case-share-via-shares-leaf/spec.md#requirement-the-case-lists-its-shares-and-a-refused-revoke-is-a-card-notice
	 */
	public function rows(string $caseId, string $uid): array {
		$rows = [];
		foreach ($this->linkShares->listForCase(caseId: $caseId) as $share) {
			$linkId = (int)($share['accessLinkId'] ?? 0);
			if ($linkId <= 0) {
				continue;
			}

			$status    = (self::STATUS[(string)($share['state'] ?? '')] ?? 'active');
			$documents = $this->documentsOf(share: $share);
			$url       = (string)($share['accessLinkUrl'] ?? '');

			$rows[] = [
				'id' => (string)$linkId,
				'kind' => ($documents > 0) ? 'file' : 'case',
				'title' => $this->titleOf(share: $share, documents: $documents),
				'meta' => $this->metaOf(share: $share),
				'status' => $status,
				'url' => $url,
				'link' => $url,
				'revocable' => (in_array($status, ['active', 'paused'], true) === true
					&& (string)($share['createdBy'] ?? '') === $uid),
			];
		}//end foreach

		return $rows;
	}//end rows()

	/**
	 * How many documents a share carries.
	 *
	 * @param array<string, mixed> $share The share record.
	 *
	 * @return int
	 */
	private function documentsOf(array $share): int {
		$documents = ($share['sharedDocuments'] ?? []);
		if (is_string($documents) === true) {
			$documents = json_decode($documents, true);
		}

		if (is_array($documents) === false) {
			return 0;
		}

		return count($documents);
	}//end documentsOf()

	/**
	 * The row title: the share's label, else what it shares.
	 *
	 * @param array<string, mixed> $share     The share record.
	 * @param int                  $documents How many documents it carries.
	 *
	 * @return string
	 */
	private function titleOf(array $share, int $documents): string {
		$label = trim((string)($share['label'] ?? ''));
		if ($label !== '') {
			return $label;
		}

		if ($documents > 0) {
			return $this->l10n->n('Link to %n document', 'Link to %n documents', $documents);
		}

		return $this->l10n->t('Link to the case');
	}//end titleOf()

	/**
	 * The muted line under the title: who created it and until when.
	 *
	 * @param array<string, mixed> $share The share record.
	 *
	 * @return string
	 */
	private function metaOf(array $share): string {
		$parts     = [];
		$createdBy = trim((string)($share['createdBy'] ?? ''));
		if ($createdBy !== '') {
			$parts[] = $this->l10n->t('by %s', [$createdBy]);
		}

		$expiresAt = trim((string)($share['expiresAt'] ?? ''));
		if ($expiresAt !== '') {
			$parts[] = $this->l10n->t('until %s', [substr($expiresAt, 0, 10)]);
		}

		return implode(' · ', $parts);
	}//end metaOf()
}//end class
