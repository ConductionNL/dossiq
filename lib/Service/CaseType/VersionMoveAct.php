<?php

/**
 * One move of a case onto another version of its case type, as it is applied.
 *
 * Built by `CaseVersionMove::move()` once the preview is re-computed, and handed through the apply and
 * journal steps: the case, the target version, the preview being applied, the reason and the actor.
 *
 * @category CaseType
 * @package  OCA\Dossiq\Service\CaseType
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

namespace OCA\Dossiq\Service\CaseType;

/**
 * One move of a case onto another version of its case type, as it is applied.
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
final class VersionMoveAct {

	/**
	 * Constructor.
	 *
	 * @param string               $caseId           The case uuid.
	 * @param string               $targetCaseTypeId The version to move onto.
	 * @param array<string, mixed> $preview          The preview being applied.
	 * @param string               $reason           Why the case is being moved.
	 * @param string               $actorUid         Who is moving it.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $targetCaseTypeId,
		public readonly array $preview,
		public readonly string $reason,
		public readonly string $actorUid,
	) {
	}//end __construct()
}//end class
