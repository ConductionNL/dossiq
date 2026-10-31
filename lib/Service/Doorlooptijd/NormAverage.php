<?php

/**
 * Dossiq NormAverage.
 *
 * The norm line of the processing-time chart: the average term (start to
 * deadline, `_normDays`) of the cases closed in the last twelve months.
 * Pure computation over already-enriched cases ({@see CaseEnricher}).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Doorlooptijd
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
 * @spec openspec/specs/doorlooptijd-norm-and-months/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Doorlooptijd;

use DateTimeImmutable;

/**
 * Averages the term of the recently closed cases.
 *
 * @spec openspec/specs/doorlooptijd-norm-and-months/spec.md
 */
class NormAverage {
	/**
	 * The rounded average term, in days, of the cases closed in the twelve
	 * months before `$today`, or null when none of them had a term.
	 *
	 * @param array<int, array<string, mixed>> $cases Enriched cases.
	 * @param DateTimeImmutable|null           $today The day the figure is read on; now when absent.
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/doorlooptijd-norm-and-months/spec.md#requirement-the-processing-time-chart-draws-the-norm-and-names-the-months
	 */
	public function averageOf(array $cases, ?DateTimeImmutable $today = null): ?int {
		$cutoff = ($today ?? new DateTimeImmutable())->modify('-12 months')->format('Y-m-d');
		$norms = [];
		foreach ($cases as $caseData) {
			$end = ($caseData['_endDate'] ?? null);
			if (($caseData['_isOpen'] ?? true) === true || $end === null || $end < $cutoff || isset($caseData['_normDays']) === false) {
				continue;
			}

			$norms[] = (int)$caseData['_normDays'];
		}

		if ($norms === []) {
			return null;
		}

		return (int)round(array_sum($norms) / count($norms));
	}//end averageOf()
}//end class
