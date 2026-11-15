<?php

/**
 * Dossiq Task Action value object.
 *
 * A createTask action with the case it runs on and the transition that triggered it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Transitions
 *
 * @author    Conduction Development Team <dev@conduction.nl>
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

/**
 * One createTask action as a handler receives it.
 *
 * @spec openspec/specs/status-transition-engine/spec.md
 */
final class TaskAction {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $actionConfig      The action configuration.
	 * @param array<string, mixed> $case              The case object.
	 * @param array<string, mixed> $transitionContext The transition context.
	 */
	public function __construct(
		public readonly array $actionConfig,
		public readonly array $case,
		public readonly array $transitionContext,
	) {
	}//end __construct()
}//end class
