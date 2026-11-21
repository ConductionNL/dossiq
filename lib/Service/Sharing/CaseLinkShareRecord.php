<?php

/**
 * Dossiq case link share record.
 *
 * What a `caseShare` record holds for an access link, read and written without
 * a store: the record written for a minted link, the link ids a stored record
 * holds, and the anchor at the end of a link address.
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
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Sharing;

/**
 * The fields of an access-link share record, and what a stored one holds.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class CaseLinkShareRecord {

	/**
	 * The share record to write for one minted link: the extra fields first,
	 * then the record's own fields, then the link's.
	 *
	 * @param MintedShare $share The minted share.
	 *
	 * @return array<string, mixed> The record.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function forMinted(MintedShare $share): array {
		return array_merge(
			$share->extra,
			$this->ownFields(share: $share),
			$this->linkFields(share: $share)
		);
	}//end forMinted()

	/**
	 * The share record's own fields: the case, the grant level, the label and who minted it.
	 *
	 * @param MintedShare $share The minted share.
	 *
	 * @return array<string, mixed> The fields.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function ownFields(MintedShare $share): array {
		// The permission level is the zaak-domain word for the same grant, kept
		// because the partner surface and the stored rows already speak it.
		$permissionLevel = 'bekijken';
		if (in_array('comment', $share->capabilities, true) === true) {
			$permissionLevel = 'bekijken_reageren';
		}

		return [
			'caseId' => $share->caseId,
			'shareType' => 'link',
			'permissionLevel' => $permissionLevel,
			'label' => $share->label,
			'status' => 'active',
			'createdBy' => $share->createdBy,
		];
	}//end ownFields()

	/**
	 * The share record's fields that describe the minted link and the files beside it.
	 *
	 * @param MintedShare $share The minted share.
	 *
	 * @return array<string, mixed> The fields.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	private function linkFields(MintedShare $share): array {
		return [
			'accessLinkId' => (int)($share->link['id'] ?? 0),
			'accessLinkUuid' => (string)($share->link['uuid'] ?? ''),
			'accessLinkUrl' => (string)($share->link['url'] ?? ''),
			'capabilities' => implode(',', $share->capabilities),
			'expiresAt' => (string)($share->link['expiresAt'] ?? ''),
			'sharedDocuments' => json_encode($share->documents),
		];
	}//end linkFields()

	/**
	 * Every link id a share holds: the case link, then each file link.
	 *
	 * @param array<string, mixed> $share The share record.
	 *
	 * @return array<int, int> The link ids.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-document-named-on-the-share-gets-its-own-file-link-req-cal-02
	 */
	public function linkIds(array $share): array {
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
	}//end linkIds()

	/**
	 * The anchor at the end of a link address.
	 *
	 * @param string $url The stored address.
	 *
	 * @return string The anchor, or an empty string.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-the-sharing-tab-names-each-links-state-and-a-holder-never-reads-case-internals-req-cal-04
	 */
	public function anchorOf(string $url): string {
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
