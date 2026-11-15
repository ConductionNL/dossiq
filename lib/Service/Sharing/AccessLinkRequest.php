<?php

/**
 * One access link to mint: who mints it, what it opens, what the holder may do and its terms.
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
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Sharing;

/**
 * One access link to mint: who mints it, what it opens, what the holder may do and its terms.
 *
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
 */
final class AccessLinkRequest {

	/**
	 * Constructor.
	 *
	 * @param string             $userId       The principal minting it.
	 * @param string             $subjectType  `object` or `file`.
	 * @param string             $subjectId    The subject.
	 * @param array<int, string> $capabilities What the holder may do.
	 * @param string|null        $expiresAt    When the link stops answering.
	 * @param string|null        $password     An optional password.
	 * @param string|null        $label        What the link is called.
	 *
	 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-a-case-share-mints-an-openregister-access-link-req-cal-01
	 */
	public function __construct(
		public readonly string $userId,
		public readonly string $subjectType,
		public readonly string $subjectId,
		public readonly array $capabilities,
		public readonly ?string $expiresAt,
		public readonly ?string $password,
		public readonly ?string $label,
	) {
	}//end __construct()
}//end class
