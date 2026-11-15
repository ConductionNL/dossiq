<?php

/**
 * Dossiq MintedShare.
 *
 * One minted case access link as the share record is written for it: the
 * case, the label, who minted it, the link, what it grants, the files minted
 * beside it and any extra fields.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Sharing
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Sharing;

/**
 * A minted case access link, as its share record is written.
 *
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
 */
final class MintedShare {

	/**
	 * Constructor.
	 *
	 * @param string                           $caseId       The case UUID.
	 * @param string                           $label        What the share is called.
	 * @param string                           $createdBy    Who minted it.
	 * @param array<string, mixed>             $link         The minted link.
	 * @param array<int, string>               $capabilities What the link grants.
	 * @param array<int, array<string, mixed>> $documents    The file links minted beside it.
	 * @param array<string, mixed>             $extra        Extra fields to record.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $label,
		public readonly string $createdBy,
		public readonly array $link,
		public readonly array $capabilities,
		public readonly array $documents,
		public readonly array $extra,
	) {
	}//end __construct()
}//end class
