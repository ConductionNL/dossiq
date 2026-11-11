<?php

/**
 * Dossiq TermRebind
 *
 * The case, target case type and reason of one re-arm.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

/**
 * One case's terms re-armed against another case type, and why.
 *
 * @spec openspec/changes/case-type-rebind/specs/zaaktype-versioning/spec.md
 */
final class TermRebind {
	/**
	 * Constructor.
	 *
	 * @param string $caseId       The case whose terms are re-armed.
	 * @param string $caseTypeSlug The target case type's slug.
	 * @param string $reason       Why the case was rebound, for the trail.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $caseTypeSlug,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
