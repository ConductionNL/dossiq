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
class PenaltyPaymentRows {
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
		$none = ['count' => 0, 'totalEuros' => 0.0, 'disputedCount' => 0];
		$empty = ['year' => $year, 'rows' => [], 'summary' => $none, 'summaryRows' => [['year' => $year] + $none]];

		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$paymentSchema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::PAYOUT_SCHEMA);
		if ($objectService === null || $register === '' || $paymentSchema === '') {
			return $empty;
		}

		try {
			$payments = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $paymentSchema, filters: []);
		} catch (Throwable $e) {
			return $empty;
		}

		$payments = ($this->payouts?->mergedRows(storedRows: $payments) ?? $payments);

		$this->found = [];
		$rows = [];
		foreach ($payments as $payment) {
			$row = $this->rowOf(objectService: $objectService, register: $register, payment: $payment);
			if (str_starts_with($row['forfeitDate'], (string)$year) === false) {
				continue;
			}

			$rows[] = $row;
		}

		usort($rows, static fn (array $a, array $b): int => strcmp($b['forfeitDate'], $a['forfeitDate']));

		$total = array_sum(array_map(static fn (array $row): float => $row['amountEuros'], $rows));
		$disputed = count(array_filter($rows, static fn (array $row): bool => $row['paymentStatus'] === 'on-hold-objection'));

		$summary = ['count' => count($rows), 'totalEuros' => round($total, 2), 'disputedCount' => $disputed];

		// The summary also as a one-row list, the shape a record list reads.
		return [
			'year'        => $year,
			'rows'        => $rows,
			'summary'     => $summary,
			'summaryRows' => [['year' => $year] + $summary],
		];
	}//end forYear()

	/**
	 * One payment as a row, named by its case.
	 *
	 * @param object               $objectService The OpenRegister object service.
	 * @param string               $register      The register id.
	 * @param array<string, mixed> $payment       The payment.
	 *
	 * @return array<string, mixed>
	 */
	private function rowOf(object $objectService, string $register, array $payment): array {
		$calculation = $this->read(
			objectService: $objectService,
			register: $register,
			schemaKey: MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA,
			id: (string)($payment['penaltyPaymentCalculation'] ?? '')
		);
		$term = $this->read(
			objectService: $objectService,
			register: $register,
			schemaKey: 'termijn_instance_schema',
			id: (string)($calculation['deadlineInstance'] ?? '')
		);
		$case = $this->read(objectService: $objectService, register: $register, schemaKey: 'case_schema', id: (string)($term['case'] ?? ''));
		$caseType = $this->read(objectService: $objectService, register: $register, schemaKey: 'case_type_schema', id: (string)($case['caseType'] ?? ''));

		$forfeitDate = (string)($calculation['stoppedOn'] ?? '');
		if ($forfeitDate === '') {
			$forfeitDate = (string)($calculation['startDate'] ?? '');
		}

		return [
			'id'             => (string)($payment['id'] ?? ($payment['@self']['id'] ?? '')),
			'caseId'         => (string)($case['id'] ?? ($term['case'] ?? '')),
			'caseIdentifier' => (string)($case['identifier'] ?? ''),
			'caseTitle'      => (string)($case['title'] ?? ''),
			'caseTypeTitle'  => (string)($caseType['title'] ?? ''),
			'requester'      => (string)($case['initiatorDisplayName'] ?? ''),
			'forfeitDate'    => $forfeitDate,
			'amountEuros'    => round(((int)($payment['amount'] ?? 0)) / 100, 2),
			'paymentStatus'  => (string)($payment['status'] ?? ''),
		];
	}//end rowOf()

	/**
	 * Read one object by the schema a setting names, once per call.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The register id.
	 * @param string $schemaKey     The setting that names the schema.
	 * @param string $id            The object id.
	 *
	 * @return array<string, mixed>
	 */
	private function read(object $objectService, string $register, string $schemaKey, string $id): array {
		$schema = (string)$this->settingsService->getConfigValue($schemaKey);
		if ($id === '' || $schema === '') {
			return [];
		}

		$key = $schemaKey.':'.$id;
		if (array_key_exists($key, $this->found) === false) {
			try {
				$this->found[$key] = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id);
			} catch (Throwable $e) {
				$this->found[$key] = null;
			}
		}

		return ($this->found[$key] ?? []);
	}//end read()
}//end class
