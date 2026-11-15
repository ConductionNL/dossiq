<?php

/**
 * Dossiq PenaltyPaymentRows.
 *
 * The penalty payments forfeited in one year, one row per payment, named by
 * the case it was forfeited on: the case number and title, the case type,
 * the requester, the day it was forfeited, the amount and where the payment
 * stands. The annual audit lists them under its total.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Termijn
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
 * @spec openspec/specs/termijn-reporting/spec.md#requirement-the-annual-audit-lists-the-forfeited-payments-by-case
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Termijn;

use OCA\Dossiq\Kernel\Contracts\PenaltyPaymentRowsInterface;
use OCA\Dossiq\Service\Money\MoneyConfigKeys;
use OCA\Dossiq\Service\Money\PayoutReader;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Throwable;

/**
 * Lists the penalty payments forfeited in a year, each named by its case.
 *
 * A payment points at its calculation, the calculation at the term, the term
 * at the case. The day a payment was forfeited is the day its calculation
 * stopped; a calculation that has not stopped counts from its start.
 *
 * @spec openspec/specs/termijn-reporting/spec.md
 */
class PenaltyPaymentRows implements PenaltyPaymentRowsInterface {
	use SearchesObjects;

	/**
	 * Objects read once per call, by schema and id.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	private array $found = [];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The app settings, for the object service and the schema ids.
	 * @param PayoutReader|null $payouts Reads the payouts from the finance app, beside the rows it does not hold.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ?PayoutReader $payouts = null,
	) {
	}//end __construct()

	/**
	 * The payments forfeited in a year, newest forfeit first, and their total.
	 *
	 * @param int $year The year.
	 *
	 * @return array<string, mixed> The year, its rows, the summary and the summary as one row (`summaryRows`).
	 *
	 * @spec openspec/specs/termijn-reporting/spec.md#requirement-the-annual-audit-lists-the-forfeited-payments-by-case
	 */
	public function forYear(int $year): array {
		$store = $this->paymentStore();
		if ($store === null) {
			return self::yearOf(year: $year, rows: []);
		}

		try {
			$payments = $this->paymentsIn(store: $store);
		} catch (Throwable $e) {
			return self::yearOf(year: $year, rows: []);
		}

		$payments = ($this->payouts?->mergedRows(storedRows: $payments) ?? $payments);
		return self::yearOf(year: $year, rows: self::inYear(rows: $this->rowsFor(store: $store, payments: $payments), year: $year));
	}//end forYear()

	/**
	 * The object service, register and payment schema, null when any is missing.
	 *
	 * @return array{objectService: object, register: string, schema: string}|null The store.
	 */
	private function paymentStore(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register      = (string)$this->settingsService->getConfigValue('register');
		$paymentSchema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::PAYOUT_SCHEMA);
		if ($objectService === null || $register === '' || $paymentSchema === '') {
			return null;
		}

		return ['objectService' => $objectService, 'register' => $register, 'schema' => $paymentSchema];
	}//end paymentStore()

	/**
	 * Every stored payment.
	 *
	 * @param array{objectService: object, register: string, schema: string} $store The payment store.
	 *
	 * @return array<int, array<string, mixed>> The payments.
	 */
	private function paymentsIn(array $store): array {
		return $this->searchObjectsAsArrays(objectService: $store['objectService'], register: $store['register'], schema: $store['schema'], filters: []);
	}//end paymentsIn()

	/**
	 * Every payment as a row, with the objects it names read once for the whole call.
	 *
	 * @param array{objectService: object, register: string, schema: string} $store    The payment store.
	 * @param array<int, array<string, mixed>>                                $payments The payments.
	 *
	 * @return array<int, array<string, mixed>> The rows, in payment order.
	 */
	private function rowsFor(array $store, array $payments): array {
		$this->found = [];
		$rows        = [];
		foreach ($payments as $payment) {
			$rows[] = $this->rowOf(store: $store, payment: $payment);
		}

		return $rows;
	}//end rowsFor()

	/**
	 * The rows forfeited in a year, newest forfeit first.
	 *
	 * @param array<int, array<string, mixed>> $rows Every row.
	 * @param int                              $year The year.
	 *
	 * @return array<int, array<string, mixed>> The year's rows.
	 */
	private static function inYear(array $rows, int $year): array {
		$inYear = array_values(array_filter($rows, static fn (array $row): bool => str_starts_with($row['forfeitDate'], (string)$year)));
		usort($inYear, static fn (array $a, array $b): int => strcmp($b['forfeitDate'], $a['forfeitDate']));

		return $inYear;
	}//end inYear()

	/**
	 * The report of a year over its rows: the rows, the summary and the summary as one row.
	 *
	 * @param int                              $year The year.
	 * @param array<int, array<string, mixed>> $rows The year's rows, newest forfeit first.
	 *
	 * @return array<string, mixed> The report.
	 */
	private static function yearOf(int $year, array $rows): array {
		$total    = array_sum(array_map(static fn (array $row): float => $row['amountEuros'], $rows));
		$disputed = count(array_filter($rows, static fn (array $row): bool => $row['paymentStatus'] === 'on-hold-objection'));
		$summary  = ['count' => count($rows), 'totalEuros' => round($total, 2), 'disputedCount' => $disputed];

		// The summary also as a one-row list, the shape a record list reads.
		return [
			'year'        => $year,
			'rows'        => $rows,
			'summary'     => $summary,
			'summaryRows' => [['year' => $year] + $summary],
		];
	}//end yearOf()

	/**
	 * One payment as a row, named by its case.
	 *
	 * @param array{objectService: object, register: string, schema: string} $store   The payment store.
	 * @param array<string, mixed>                                            $payment The payment.
	 *
	 * @return array<string, mixed>
	 */
	private function rowOf(array $store, array $payment): array {
		[$calculation, $term, $case, $caseType] = $this->linkedTo(store: $store, payment: $payment);

		return [
			'id'             => (string)($payment['id'] ?? ($payment['@self']['id'] ?? '')),
			'caseId'         => (string)($case['id'] ?? ($term['case'] ?? '')),
			'caseIdentifier' => (string)($case['identifier'] ?? ''),
			'caseTitle'      => (string)($case['title'] ?? ''),
			'caseTypeTitle'  => (string)($caseType['title'] ?? ''),
			'requester'      => (string)($case['initiatorDisplayName'] ?? ''),
			'forfeitDate'    => self::forfeitDateOf(calculation: $calculation),
			'amountEuros'    => round(((int)($payment['amount'] ?? 0)) / 100, 2),
			'paymentStatus'  => (string)($payment['status'] ?? ''),
		];
	}//end rowOf()

	/**
	 * The calculation, term, case and case type a payment leads to, each [] when absent.
	 *
	 * @param array{objectService: object, register: string, schema: string} $store   The payment store.
	 * @param array<string, mixed>                                            $payment The payment.
	 *
	 * @return array{0: array, 1: array, 2: array, 3: array} The four objects, in that order.
	 */
	private function linkedTo(array $store, array $payment): array {
		$calculation = $this->read(store: $store, ref: [MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA, (string)($payment['penaltyPaymentCalculation'] ?? '')]);
		$term        = $this->read(store: $store, ref: ['termijn_instance_schema', (string)($calculation['deadlineInstance'] ?? '')]);
		$case        = $this->read(store: $store, ref: ['case_schema', (string)($term['case'] ?? '')]);
		$caseType    = $this->read(store: $store, ref: ['case_type_schema', (string)($case['caseType'] ?? '')]);

		return [$calculation, $term, $case, $caseType];
	}//end linkedTo()

	/**
	 * The day a calculation forfeited: the day it stopped, else the day it started.
	 *
	 * @param array<string, mixed> $calculation The penalty payment calculation.
	 *
	 * @return string The date, or ''.
	 */
	private static function forfeitDateOf(array $calculation): string {
		$forfeitDate = (string)($calculation['stoppedOn'] ?? '');
		if ($forfeitDate === '') {
			$forfeitDate = (string)($calculation['startDate'] ?? '');
		}

		return $forfeitDate;
	}//end forfeitDateOf()

	/**
	 * Read one object by the schema a setting names, once per call.
	 *
	 * @param array{objectService: object, register: string, schema: string} $store The payment store, for its object service and register.
	 * @param array{0: string, 1: string}                                     $ref   The setting that names the schema, and the object id.
	 *
	 * @return array<string, mixed>
	 */
	private function read(array $store, array $ref): array {
		$schema = (string)$this->settingsService->getConfigValue($ref[0]);
		if ($ref[1] === '' || $schema === '') {
			return [];
		}

		$key = implode(':', $ref);
		if (array_key_exists($key, $this->found) === false) {
			try {
				$this->found[$key] = $this->findObjectAsArray(objectService: $store['objectService'], register: $store['register'], schema: $schema, id: $ref[1]);
			} catch (Throwable $e) {
				$this->found[$key] = null;
			}
		}

		return ($this->found[$key] ?? []);
	}//end read()
}//end class
