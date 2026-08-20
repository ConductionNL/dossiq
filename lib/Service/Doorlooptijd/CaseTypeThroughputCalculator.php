<?php

/**
 * Procest CaseTypeThroughputCalculator.
 *
 * The case-type breakdown of the throughput-time dashboard: the average
 * realised throughput (in days) of *closed* cases, grouped per caseType and
 * ranked slowest-first, so a coordinator can see which case type is dragging
 * the overall doorlooptijd.
 *
 * Split out of DoorlooptijdService so that service keeps only the load +
 * orchestrate role. Unlike the other metric family this one never looks at a
 * deadline — it measures elapsed time only — which is exactly why it is its
 * own calculator rather than part of
 * {@see DeadlineComplianceCalculator}.
 *
 * Pure computation over already-enriched cases ({@see CaseEnricher}) —
 * nothing is read from OpenRegister.
 *
 * @category Service
 * @package  OCA\Procest\Service\Doorlooptijd
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Doorlooptijd;

/**
 * Computes average closed-case throughput per case-type.
 *
 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
 */
class CaseTypeThroughputCalculator {
	/**
	 * Average closed-case throughput by case-type.
	 *
	 * @param array<int, array<string, mixed>> $cases Enriched cases.
	 * @param array<int, array<string, mixed>> $caseTypes Indexed case-type metadata.
	 *
	 * @return array<int, array{id: string, title: string, avgDays: int, count: int}>
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	public function computeCaseTypeBreakdown(array $cases, array $caseTypes): array {
		$accum = $this->accumulateThroughputByCaseType(cases: $cases);

		$caseTypeIndex = [];
		foreach ($caseTypes as $caseType) {
			$id = (string)($caseType['id'] ?? '');
			if ($id !== '') {
				$caseTypeIndex[$id] = $caseType;
			}
		}

		$out = [];
		foreach ($accum as $caseTypeId => $stats) {
			$title = $caseTypeId;
			if (isset($caseTypeIndex[$caseTypeId]['title']) === true) {
				$title = (string)$caseTypeIndex[$caseTypeId]['title'];
			}

			$out[] = [
				'id' => $caseTypeId,
				'title' => $title,
				'avgDays' => (int)round($stats['sum'] / $stats['count']),
				'count' => $stats['count'],
			];
		}

		usort(
			$out,
			static fn (array $left, array $right): int => ($right['avgDays'] <=> $left['avgDays'])
		);

		return $out;
	}//end computeCaseTypeBreakdown()

	/**
	 * Sum and count closed-case throughput days per case-type id.
	 *
	 * @param array<int, array<string, mixed>> $cases Enriched cases.
	 *
	 * @return array<string, array{sum: int, count: int}>
	 *
	 * @spec openspec/specs/doorlooptijd-dashboard/spec.md
	 */
	private function accumulateThroughputByCaseType(array $cases): array {
		$accum = [];
		foreach ($cases as $caseData) {
			if ($caseData['_isOpen'] === true || $caseData['_throughputDays'] === null) {
				continue;
			}

			$caseTypeId = (string)($caseData['caseType'] ?? '');
			if ($caseTypeId === '') {
				continue;
			}

			if (isset($accum[$caseTypeId]) === false) {
				$accum[$caseTypeId] = ['sum' => 0, 'count' => 0];
			}

			$accum[$caseTypeId]['sum'] += $caseData['_throughputDays'];
			$accum[$caseTypeId]['count']++;
		}//end foreach

		return $accum;
	}//end accumulateThroughputByCaseType()
}//end class
