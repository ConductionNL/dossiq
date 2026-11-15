<?php

/**
 * A WOO besluit as it was just assembled: the case, the decision raised in decidiq and the saved decision object.
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
 * A WOO besluit as it was just assembled: the case, the decision raised in decidiq and the saved decision object.
 *
 * @spec exclude Parameter object, no functional requirement.
 */
final class AssembledDecision {
	/**
	 * Constructor.
	 *
	 * @param string $caseId The case UUID
	 * @param string $decisionRef The decision raised in decidiq
	 * @param object $decision The saved decision object
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $decisionRef,
		public readonly object $decision,
	) {
	}//end __construct()
}//end class
