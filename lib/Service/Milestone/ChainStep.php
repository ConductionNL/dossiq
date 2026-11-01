<?php

/**
 * One milestone on the way down a dependency chain, and how deep the walk is.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Milestone
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Milestone;

/**
 * A milestone identifier at a depth of the dependency walk.
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
 */
final class ChainStep {

	/**
	 * Constructor.
	 *
	 * @param string $identifier The milestone.
	 * @param int    $depth      How deep the walk is.
	 */
	public function __construct(
		public readonly string $identifier,
		public readonly int $depth,
	) {
	}//end __construct()

	/**
	 * The step to a predecessor, one level deeper.
	 *
	 * @param string $identifier The predecessor.
	 *
	 * @return self The deeper step.
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function toPredecessor(string $identifier): self {
		return new self(identifier: $identifier, depth: ($this->depth + 1));
	}//end toPredecessor()
}//end class
