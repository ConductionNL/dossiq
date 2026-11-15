<?php

/**
 * Dossiq CaseReopening.
 *
 * One reopening of a closed case: the case, the initial status it goes back
 * to, the final status it leaves and why it is reopened.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * A closed case being reopened.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
final class CaseReopening {

	/**
	 * Constructor.
	 *
	 * @param string $caseId     Case UUID.
	 * @param string $initial    The status the case goes back to.
	 * @param string $fromStatus The final status the case leaves.
	 * @param string $reason     Why the case is reopened.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $initial,
		public readonly string $fromStatus,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
