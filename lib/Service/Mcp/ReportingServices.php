<?php

/**
 * The reports the reporting tools answer from: deadlines, lead times, KPIs and the work queue.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Mcp
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
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Mcp;

use OCA\Dossiq\Service\DeadlineReportingService;
use OCA\Dossiq\Service\DoorlooptijdService;
use OCA\Dossiq\Service\KpiAggregationService;
use OCA\Dossiq\Service\WorkQueueService;

/**
 * The reports the reporting tools answer from: deadlines, lead times, KPIs and the work queue.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-integration/spec.md#requirement-req-mcp-202-curated-aggregation-reads
 */
final class ReportingServices {
	/**
	 * Constructor.
	 *
	 * @param DeadlineReportingService $deadlines Owns the termijn KPI snapshot.
	 * @param DoorlooptijdService      $leadTimes Owns the lead-time metrics.
	 * @param KpiAggregationService    $kpis      Owns the personal KPI overview.
	 * @param WorkQueueService         $workQueue Owns the per-handler workload.
	 */
	public function __construct(
		public readonly DeadlineReportingService $deadlines,
		public readonly DoorlooptijdService $leadTimes,
		public readonly KpiAggregationService $kpis,
		public readonly WorkQueueService $workQueue,
	) {
	}//end __construct()
}//end class
