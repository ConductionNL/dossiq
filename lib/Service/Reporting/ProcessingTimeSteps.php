<?php

/**
 * The steps of a processing time report: compute, lay out as rows, publish the series.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Reporting
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Reporting;

/**
 * The steps of a processing time report: compute, lay out as rows, publish the series.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
final class ProcessingTimeSteps {
	/**
	 * Constructor.
	 *
	 * @param ProcessingTimeCalculator      $calculator The chart arithmetic.
	 * @param ProcessingTimeRows            $rows       The table rows.
	 * @param ProcessingTimeSeriesPublisher $publisher  Hands the chart series to the analytics leaf.
	 */
	public function __construct(
		public readonly ProcessingTimeCalculator $calculator,
		public readonly ProcessingTimeRows $rows,
		public readonly ProcessingTimeSeriesPublisher $publisher,
	) {
	}//end __construct()
}//end class
