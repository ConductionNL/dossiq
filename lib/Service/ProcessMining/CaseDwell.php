<?php

/**
 * One case whose status visits are measured: its id, its close moment and the report window.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ProcessMining
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
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

use DateTimeImmutable;

/**
 * A case in a dwell-time report.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
final class CaseDwell {

	/**
	 * Constructor.
	 *
	 * @param string                 $caseId   The case id.
	 * @param DateTimeImmutable|null $closedAt The case's close moment, or null when still open.
	 * @param DwellWindow            $window   The report window.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly ?DateTimeImmutable $closedAt,
		public readonly DwellWindow $window,
	) {
	}//end __construct()
}//end class
