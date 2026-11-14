<?php

/**
 * Locks the process-mining page summary: both phase-day matrices and where cases stick.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\ProcessMining;

use OCA\Dossiq\Service\ProcessMining\PhaseSummary;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The page reads the mean and the median matrix and the stuck ranking, with case types without a bottleneck left out.
 */
#[CoversClass(PhaseSummary::class)]
final class PhaseSummaryForPageTest extends TestCase {

	public function testThePageReadsBothMatricesAndTheStuckCases(): void {
		$caseTypes = [
			[
				'id' => 'woo',
				'title' => 'Woo',
				'dwellTime' => [['statusName' => 'Ontvangst', 'meanHours' => 48.0, 'medianHours' => 24.0]],
				'bottlenecks' => [['statusId' => 's1', 'statusName' => 'Ontvangst', 'medianHours' => 72, 'visitCount' => 4, 'score' => 2.5]],
			],
			[
				'id' => 'permit',
				'title' => 'Permit',
				'dwellTime' => [['statusName' => 'Ontvangst', 'meanHours' => 96.0, 'medianHours' => 72.0]],
				'bottlenecks' => [],
			],
			[
				'id' => 'bezwaar',
				'title' => 'Bezwaar',
				'dwellTime' => [],
				'bottlenecks' => ['not a row'],
			],
		];

		$page = (new PhaseSummary())->forPage(caseTypes: $caseTypes);

		$this->assertSame(
			[
				'phaseDays' => [
					'mean' => [
						'columns' => ['Ontvangst'],
						'rows' => [
							['label' => 'Woo', 'values' => [2]],
							['label' => 'Permit', 'values' => [4]],
						],
					],
					'median' => [
						'columns' => ['Ontvangst'],
						'rows' => [
							['label' => 'Woo', 'values' => [1]],
							['label' => 'Permit', 'values' => [3]],
						],
					],
				],
				'stuck' => [
					['id' => 'woo:s1', 'caseType' => 'Woo', 'phase' => 'Ontvangst', 'days' => 3, 'visits' => 4, 'level' => 'bottleneck'],
				],
			],
			$page
		);
	}//end testThePageReadsBothMatricesAndTheStuckCases()

	public function testAnEmptyReportHasNothingOnThePage(): void {
		$this->assertSame(
			['phaseDays' => ['mean' => ['columns' => [], 'rows' => []], 'median' => ['columns' => [], 'rows' => []]], 'stuck' => []],
			(new PhaseSummary())->forPage(caseTypes: [])
		);
	}//end testAnEmptyReportHasNothingOnThePage()
}//end class
