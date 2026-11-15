<?php

/**
 * Dossiq PlannedFlowQuery.
 *
 * Which planned flows a case's list asks for: the case's own, still to come from a given day.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Flow
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
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

/**
 * The case whose planned flows are listed, and the day they are listed from.
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */
final class PlannedFlowQuery {

	/**
	 * Constructor.
	 *
	 * @param string             $caseId The case UUID.
	 * @param \DateTimeImmutable $today  Midnight today.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly \DateTimeImmutable $today,
	) {
	}//end __construct()
}//end class
