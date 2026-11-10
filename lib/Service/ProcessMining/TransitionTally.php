<?php

/**
 * The running count of status transitions over a set of cases
 *
 * One row per from-to pair with its count and rework count, and the totals:
 * the progress TransitionMatrixBuilder::computeTransitionMatrix() fills in one
 * transition at a time.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\ProcessMining
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
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\ProcessMining;

/**
 * The progress of one transition count; it never leaves the builder that built it.
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T01
 */
final class TransitionTally {

	/**
	 * One row per "from::to" pair, in the order first met.
	 *
	 * @var array<string, array{from: string, to: string, count: int, reworkCount: int}>
	 */
	public array $matrix = [];

	/**
	 * All transitions counted.
	 *
	 * @var integer
	 */
	public int $totalCount = 0;

	/**
	 * The transitions that went back to an earlier status.
	 *
	 * @var integer
	 */
	public int $reworkSum = 0;
}//end class
