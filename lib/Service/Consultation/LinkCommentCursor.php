<?php

/**
 * Dossiq LinkCommentCursor.
 *
 * Which comments on a case still count as uncollected advice from one
 * consultation link: the ones written as the link's principal, newer than
 * the comment already collected.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Consultation
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
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Consultation;

/**
 * The link principal and the id of the comment already collected.
 *
 * @spec openspec/specs/case-share-via-shares-leaf/spec.md#requirement-an-external-consultation-rides-the-links-comment-capability-req-cal-03
 */
final class LinkCommentCursor {

	/**
	 * Constructor.
	 *
	 * @param string $actorId The link principal, `link:<uuid>`.
	 * @param int    $after   The id already collected.
	 */
	public function __construct(
		public readonly string $actorId,
		public readonly int $after,
	) {
	}//end __construct()
}//end class
