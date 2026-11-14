<?php

/**
 * Dossiq TransitionedCase.
 *
 * The case a status transition moved and the context of that transition,
 * as each of the transition's side effects receives them.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
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

namespace OCA\Dossiq\Service\Transitions;

/**
 * The case a transition moved, and the transition's context.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
final class TransitionedCase {

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $case              The case.
	 * @param array<string, mixed> $transitionContext The transition context.
	 */
	public function __construct(
		public readonly array $case,
		public readonly array $transitionContext,
	) {
	}//end __construct()
}//end class
