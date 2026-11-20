<?php

/**
 * One sweep that switches off the planned follow-ups with nothing left to do.
 *
 * Built by `PlannedSeriesLedger::retireSpent()` and never handed out.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Flow;

use DateTimeImmutable;

/**
 * The flow engine and the day one retirement sweep judges against.
 *
 * @spec openspec/specs/workflow-definition-engine/spec.md
 */
final class RetirementSweep {
	/**
	 * Constructor.
	 *
	 * @param object            $flowService OpenRegister's FlowService.
	 * @param DateTimeImmutable $day         The day to judge against.
	 */
	public function __construct(
		public readonly object $flowService,
		public readonly DateTimeImmutable $day,
	) {
	}//end __construct()
}//end class
