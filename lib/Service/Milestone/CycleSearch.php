<?php

/**
 * The state of one search for a dependency cycle: the definitions and how far each node is walked.
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
 * One cycle search. The node colours are written as the walk goes.
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
 */
final class CycleSearch {

	/**
	 * Node colours by identifier: 'walking' while on the current path, 'done' once cleared.
	 *
	 * @var array<string, string>
	 */
	public array $state = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>> $byIdentifier Every definition, by identifier.
	 */
	public function __construct(
		public readonly array $byIdentifier,
	) {
	}//end __construct()
}//end class
