<?php

/**
 * The analysers a process mining report runs over the loaded cases.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ProcessMining
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
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

/**
 * The analysers a process mining report runs over the loaded cases.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
final class ProcessMiningAnalysers {
	/**
	 * Constructor.
	 *
	 * @param DwellTimeAnalyzer         $dwellTimeAnalyzer    Dwell-interval reconstruction + bottleneck ranking.
	 * @param TransitionMatrixBuilder   $transitionBuilder    Transition matrix + rework detection.
	 * @param ThroughputTrendCalculator $throughputCalculator Weekly closed-case throughput trend.
	 */
	public function __construct(
		public readonly DwellTimeAnalyzer $dwellTimeAnalyzer,
		public readonly TransitionMatrixBuilder $transitionBuilder,
		public readonly ThroughputTrendCalculator $throughputCalculator,
	) {
	}//end __construct()
}//end class
