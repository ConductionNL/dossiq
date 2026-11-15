<?php

/**
 * Dossiq Document Counts value object.
 *
 * How many documents of a case are under review, and how many still wait for an assessment.
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
 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transitions;

/**
 * The document counts a document assessment guard decides on.
 *
 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/status-transition-engine/spec.md
 */
final class DocumentCounts {
	/**
	 * Constructor.
	 *
	 * @param int $documents   The documents under review.
	 * @param int $outstanding The documents still waiting for an assessment.
	 */
	public function __construct(
		public readonly int $documents,
		public readonly int $outstanding,
	) {
	}//end __construct()
}//end class
