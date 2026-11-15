<?php

/**
 * The bezwaar case and the decision it contests.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Bezwaar
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

namespace OCA\Dossiq\Service\Bezwaar;

/**
 * The bezwaar case and the decision it contests.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class ObjectionLink {
	/**
	 * Constructor.
	 *
	 * @param string $objectionCaseId UUID of the bezwaar case
	 * @param string $contestedDecisionId UUID of the contested decision
	 */
	public function __construct(
		public readonly string $objectionCaseId,
		public readonly string $contestedDecisionId,
	) {
	}//end __construct()
}//end class
