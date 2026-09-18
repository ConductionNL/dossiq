<?php

/**
 * Dossiq Cofinanciering Validator.
 *
 * Co-financing validation for multi-party funded projects (REQ-SUB-008):
 * verifies that the sum of co-financing contributions plus the requested
 * subsidy equals the project total, and detects EU co-financing for
 * compatibility cross-checks. Pure validation logic, fully unit-tested,
 * with no persistence coupling — it operates on the begroting/cofinanciering
 * arrays handed in by the calling service.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Subsidie
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

namespace OCA\Dossiq\Service\Subsidie;

/**
 * Pure co-financing reconciliation and EU-detection helpers.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/subsidieverlening-keten/specs.md
 */
class CofinancieringValidator {
	/**
	 * EU co-financing party markers (case-insensitive substring match).
	 *
	 * @var array<int, string>
	 */
	private const EU_MARKERS = ['efro', 'esf', 'eu', 'europ', 'interreg', 'horizon'];

	/**
	 * Sum a list of contribution rows by their "amount" field.
	 *
	 * @param array<int, array<string, mixed>> $rows The contribution rows.
	 *
	 * @return float The total in EUR.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function sumBedragen(array $rows): float {
		$sum = 0.0;
		foreach ($rows as $row) {
			// 🔴 `bedrag` FIRST, BECAUSE THAT IS WHAT THE SCHEMA DECLARES.
			// `subsidieAanvraag.coFinancingList` says `[{partij, bedrag,
			// percentage}]`, and this method read `amount` only. Nothing
			// reported the mismatch because nothing called this class: its own
			// suite was written against the implementation rather than the
			// declaration, so the test and the code agreed with each other and
			// neither agreed with the data. Every real row would have summed to
			// zero and every declared budget would have been refused.
			// `amount` is still read as a fallback, for that suite and for any
			// row an earlier caller may have written in its shape.
			$sum += (float)($row['bedrag'] ?? ($row['amount'] ?? 0));
		}

		return round($sum, 2);
	}//end sumBedragen()

	/**
	 * Whether subsidy + co-financing reconcile to the project total
	 * (REQ-SUB-008). Tolerates sub-cent floating-point drift.
	 *
	 * @param float $subsidyAmount The requested/granted subsidy.
	 * @param array<int, array<string, mixed>> $cofinanciering The co-financing rows.
	 * @param float $projectTotal The project total.
	 *
	 * @return bool True when the funding sources reconcile to the total.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function reconciles(float $subsidyAmount, array $cofinanciering, float $projectTotal): bool {
		$total = ($subsidyAmount + $this->sumBedragen(rows: $cofinanciering));
		return abs($total - $projectTotal) < 0.01;
	}//end reconciles()

	/**
	 * Whether any co-financing party is an EU source (REQ-SUB-008).
	 *
	 * @param array<int, array<string, mixed>> $cofinanciering The co-financing rows.
	 *
	 * @return bool True when EU co-financing is present.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function hasEuCofinanciering(array $cofinanciering): bool {
		foreach ($cofinanciering as $row) {
			$partij = strtolower((string)($row['partij'] ?? ''));
			foreach (self::EU_MARKERS as $marker) {
				if ($partij !== '' && str_contains($partij, $marker) === true) {
					return true;
				}
			}
		}

		return false;
	}//end hasEuCofinanciering()

	/**
	 * Validate a co-financing breakdown, returning a structured result with
	 * a machine-readable error code on failure (REQ-SUB-008).
	 *
	 * @param float $subsidyAmount The requested/granted subsidy.
	 * @param array<int, array<string, mixed>> $cofinanciering The co-financing rows.
	 * @param float $projectTotal The project total.
	 *
	 * @return array{valid: bool, error: string|null, euCofinanciering: bool}
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function validate(float $subsidyAmount, array $cofinanciering, float $projectTotal): array {
		if ($projectTotal <= 0.0) {
			return ['valid' => false, 'error' => 'COFIN_PROJECT_TOTAL_INVALID', 'euCofinanciering' => false];
		}

		$euCofin = $this->hasEuCofinanciering(cofinanciering: $cofinanciering);
		if ($this->reconciles(subsidyAmount: $subsidyAmount, cofinanciering: $cofinanciering, projectTotal: $projectTotal) === false) {
			return ['valid' => false, 'error' => 'COFIN_SUM_MISMATCH', 'euCofinanciering' => $euCofin];
		}

		return ['valid' => true, 'error' => null, 'euCofinanciering' => $euCofin];
	}//end validate()
}//end class
