<?php

/**
 * A priority floor a term rule raises on a case: the case, the floor, the rule and what to report when the write cannot be read back.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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

namespace OCA\Dossiq\Service;

/**
 * A priority floor a term rule raises on a case: the case, the floor, the rule and what to report when the write cannot be read back.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class PriorityFloorRaise {
	/**
	 * Constructor.
	 *
	 * @param string $caseId The case
	 * @param string $floor The floor to store
	 * @param string $rule The declared rule that asked for it
	 * @param string $fallback What to report when the write cannot be read back
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $floor,
		public readonly string $rule,
		public readonly string $fallback,
	) {
	}//end __construct()
}//end class
