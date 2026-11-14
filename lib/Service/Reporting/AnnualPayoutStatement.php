<?php

/**
 * The annual statement of the penalty payouts paid in a year.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Reporting;

use OCA\Dossiq\Service\Money\MoneyConfigKeys;
use OCA\Dossiq\Service\Money\PayoutReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;

/**
 * Reads the stored payouts (and the finance app's, through the payout reader),
 * keeps those paid in the year, and lists them with a count, the totals and a
 * warning per payout without a payment reference. Moved out of
 * DeadlineReportingService, which delegates to it and adds each row's generic
 * key names, as it did before.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class AnnualPayoutStatement {

	use SearchesObjects;

	/**
	 * The annual statement when the payouts cannot be read.
	 *
	 * @var array<string, mixed>
	 */
	public const EMPTY_STATEMENT = ['rows' => [], 'summary' => ['count' => 0, 'totalCents' => 0, 'totalEuros' => 0.0, 'warningCount' => 0]];

	/**
	 * Constructor.
	 *
	 * @param SettingsService   $settingsService Settings: the object service, register and payout schema.
	 * @param PayoutReader|null $payouts         Reads the payouts from the finance app, beside the stored ones.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ?PayoutReader $payouts = null,
	) {
	}//end __construct()

	/**
	 * The statement of the payouts paid in a year.
	 *
	 * @param int $year The year.
	 *
	 * @return array<string, mixed> The statement, or the empty one when the payouts cannot be read.
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function forYear(int $year): array {
		$source = $this->payoutSource();
		if ($source === null) {
			return self::EMPTY_STATEMENT;
		}

		try {
			$rows = $this->searchObjectsAsArrays(objectService: $source[0], register: $source[1], schema: $source[2], filters: []);
		} catch (\Throwable $e) {
			return self::EMPTY_STATEMENT;
		}

		$paid = $this->paidInYear(rows: ($this->payouts?->mergedRows(storedRows: $rows, paidInYear: $year) ?? $rows), year: $year);

		return $this->statementOf(year: $year, paid: $paid);
	}//end forYear()

	/**
	 * Where the payouts are stored, or null when OpenRegister or a setting is missing.
	 *
	 * @return array{0: object, 1: string, 2: string}|null The object service, register and payout schema.
	 */
	private function payoutSource(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$uSchema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::PAYOUT_SCHEMA);
		if ($objectService === null || $register === '' || $uSchema === '') {
			return null;
		}

		return [$objectService, $register, $uSchema];
	}//end payoutSource()

	/**
	 * The payouts paid in the year, in order.
	 *
	 * @param array<int, array<string, mixed>> $rows The payouts.
	 * @param int                              $year The year.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function paidInYear(array $rows, int $year): array {
		$yearPrefix = (string)$year;

		return array_values(
			array_filter(
				$rows,
				static fn (array $row): bool => str_starts_with((string)($row['actualPaymentDate'] ?? ''), $yearPrefix)
			)
		);
	}//end paidInYear()

	/**
	 * A warning for each payout that carries no payment reference.
	 *
	 * @param array<int, array<string, mixed>> $paid The payouts of the year.
	 *
	 * @return array<int, string>
	 */
	private function missingReferenceWarnings(array $paid): array {
		$warnings = [];
		foreach ($paid as $row) {
			if (($row['betalingsreferentie'] ?? '') === '') {
				$warnings[] = 'Missing betalingsreferentie for ' . ((string)($row['reference'] ?? ''));
			}
		}

		return $warnings;
	}//end missingReferenceWarnings()

	/**
	 * One payout as the statement lists it.
	 *
	 * @param array<string, mixed> $row A payout.
	 *
	 * @return array<string, mixed>
	 */
	private function payoutRow(array $row): array {
		return [
			'reference' => (string)($row['reference'] ?? ''),
			'bedragCents' => (int)($row['amount'] ?? 0),
			'actualPaymentDate' => (string)($row['actualPaymentDate'] ?? ''),
			'betalingsreferentie' => (string)($row['betalingsreferentie'] ?? ''),
			'status' => (string)($row['status'] ?? ''),
			'legalBasis' => (string)($row['legalBasis'] ?? ''),
			'iban' => (string)($row['iban'] ?? ''),
		];
	}//end payoutRow()

	/**
	 * The statement of the payouts paid in a year.
	 *
	 * @param int                              $year The year.
	 * @param array<int, array<string, mixed>> $paid The payouts paid in it.
	 *
	 * @return array<string, mixed>
	 */
	private function statementOf(int $year, array $paid): array {
		$outRows = array_map(fn (array $row): array => $this->payoutRow(row: $row), $paid);
		$warnings = $this->missingReferenceWarnings(paid: $paid);

		return [
			'year' => $year,
			'jaar' => $year,
			'rows' => $outRows,
			'summary' => $this->statementSummary(rows: $outRows, warnings: $warnings),
			'warnings' => $warnings,
		];
	}//end statementOf()

	/**
	 * The statement's count, totals and warning count.
	 *
	 * @param array<int, array<string, mixed>> $rows     The statement's rows.
	 * @param array<int, string>               $warnings Its warnings.
	 *
	 * @return array{count: int, totalCents: int, totalEuros: float, warningCount: int}
	 */
	private function statementSummary(array $rows, array $warnings): array {
		$total = (int)array_sum(array_column($rows, 'bedragCents'));

		return [
			'count' => count($rows),
			'totalCents' => $total,
			'totalEuros' => round($total / 100, 2),
			'warningCount' => count($warnings),
		];
	}//end statementSummary()
}//end class
