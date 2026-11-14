<?php

/**
 * A flow step being removed from the edge list, and the steps its inbound edges move to.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

/**
 * A flow step being removed from the edge list, and the steps its inbound edges move to.
 *
 * @spec openspec/specs/automatic-actions/spec.md
 */
final class RemovedStep {

	/**
	 * Constructor.
	 *
	 * @param string             $stepId     The step being removed.
	 * @param array<int, string> $successors The step's successors.
	 *
	 * @spec openspec/specs/automatic-actions/spec.md
	 */
	public function __construct(
		public readonly string $stepId,
		public readonly array $successors,
	) {
	}//end __construct()
}//end class
