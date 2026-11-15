<?php

/**
 * Dossiq ReachableStatuses.
 *
 * A case type's declared statuses, the one a new case starts in, and the ones a case can get to from there.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/specs/case-type-publish-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

/**
 * The declared statuses, the initial status and the reachable status ids.
 *
 * @spec openspec/specs/case-type-publish-validation/spec.md
 */
final class ReachableStatuses {

	/**
	 * Constructor.
	 *
	 * @param array<string, array{title: string, final: bool}> $statuses The declared statuses, by id.
	 * @param string                                           $initial  The status a new case starts in.
	 * @param array<string, true>                              $reached  The reachable status ids, the initial one included.
	 */
	public function __construct(
		public readonly array $statuses,
		public readonly string $initial,
		public readonly array $reached,
	) {
	}//end __construct()
}//end class
