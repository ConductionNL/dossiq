<?php

/**
 * The case a transition would move, and where it would move it.
 *
 * Built once by `TransitionPreconditions::withheldReasons()` and handed through the steps that ask
 * each dependency: the case, its id, the destination status and whether that status closes the case.
 *
 * @category Transitions
 * @package  OCA\Dossiq\Service\Transitions
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

namespace OCA\Dossiq\Service\Transitions;

/**
 * The case a transition would move, and where it would move it.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
final class TransitionDestination {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $case        The case.
	 * @param string               $caseId      The case id.
	 * @param string               $toStatus    The destination status.
	 * @param bool                 $toIsClosing Whether the destination closes the case.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $case,
		public readonly string $caseId,
		public readonly string $toStatus,
		public readonly bool $toIsClosing,
	) {
	}//end __construct()
}//end class
