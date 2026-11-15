<?php

/**
 * What a caller asked to link: the case, the target and the extra record fields.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Relation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Relation;

/**
 * What a caller asked to link: the case, the target and the extra record fields.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class RoleLinkRequest {
	/**
	 * Constructor.
	 *
	 * @param string $caseId The case being linked
	 * @param string $targetId The case or object the link points at
	 * @param array<string, mixed> $payload Extra fields for the record the role opens
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $targetId,
		public readonly array $payload,
	) {
	}//end __construct()
}//end class
