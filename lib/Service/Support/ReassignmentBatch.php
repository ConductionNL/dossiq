<?php

/**
 * Dossiq ReassignmentBatch value object.
 *
 * The batch-wide invariants of a single bulk reassignment run: who the work
 * moves from, who it moves to, which coordinator ordered it, the shared batch
 * id every per-item audit entry carries, and the one timestamp stamped on all
 * of them. Grouping them keeps the per-item worker's signature small and
 * guarantees every item in a run is audited with the exact same batch header.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
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
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * Immutable batch header shared by every item of one bulk reassignment.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class ReassignmentBatch {
	/**
	 * Constructor.
	 *
	 * @param string $fromUser Previous handler the work is taken from.
	 * @param string $toUser New handler the work is given to.
	 * @param string $actorId Acting coordinator who ordered the batch.
	 * @param string $batchId Shared id stamped on every audit entry.
	 * @param string $now ISO timestamp stamped on every audit entry.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $fromUser,
		public readonly string $toUser,
		public readonly string $actorId,
		public readonly string $batchId,
		public readonly string $now,
	) {
	}//end __construct()
}//end class
