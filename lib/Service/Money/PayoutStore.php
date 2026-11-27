<?php

/**
 * Where the payouts of a decided dwangsom are read and written
 *
 * The accruing amount a payout pays and the payout rows themselves live in
 * two configured schemas of dossiq's register. This reads the payable amount
 * of a calculation, saves a payout row and finds the row a finance callback
 * names by its reference, refusing with one sentence when the store is not
 * configured and with another when a read or write fails. It decides nothing
 * about a payout: PayoutService does.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Money
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
 * @spec openspec/specs/payout/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * Reads accruing amounts and reads and writes payout rows.
 *
 * @spec openspec/specs/payout/spec.md
 */
final class PayoutStore {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The object service, the register and the schema keys.
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The amount a settled calculation pays, in EUR cents.
	 *
	 * Both schemas are checked first, so a payout is refused before anything
	 * is read when either is not configured.
	 *
	 * @param string $calculationId The accruing amount (berekening) id.
	 *
	 * @return int The definitive amount, or the cumulative amount when none is definitive.
	 *
	 * @throws RuntimeException When the store is not configured, the calculation is missing or has nothing payable.
	 *
	 * @spec openspec/specs/payout/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function payableAmount(string $calculationId): int {
		[$objectService, $register, $bSchema] = $this->configured(
			schemaKeys: [MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA, MoneyConfigKeys::PAYOUT_SCHEMA]
		);

		$calculation = $this->calculation(
			amounts: new MoneyStore(objectService: $objectService, register: $register, schema: $bSchema),
			calculationId: $calculationId
		);
		if ($calculation === null) {
			throw new RuntimeException('Accruing amount not found: ' . $calculationId);
		}

		$final = (int)($calculation['definitiveAmount'] ?? $calculation['cumulativeAmount'] ?? 0);
		if ($final <= 0) {
			throw new RuntimeException('DwangsomBerekening has no payable amount');
		}

		return $final;
	}//end payableAmount()

	/**
	 * The payout row carrying a reference.
	 *
	 * @param string $reference Payment reference.
	 *
	 * @return array<string, mixed> The first matching row.
	 *
	 * @throws RuntimeException When the store is not configured, the lookup fails or the reference is unknown.
	 *
	 * @spec openspec/specs/payout/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function byReference(string $reference): array {
		$rows = $this->withReference(payouts: $this->payouts(), reference: $reference);

		$row = null;
		if (count($rows) > 0) {
			$row = $rows[0];
		}

		if (is_array($row) === false) {
			throw new RuntimeException('No DwangsomUitbetaling found for referentie ' . $reference);
		}

		return $row;
	}//end byReference()

	/**
	 * Save a payout row.
	 *
	 * @param array<string, mixed> $row The row to save.
	 *
	 * @return array<string, mixed> The saved row, or the supplied row when the store answers nothing.
	 *
	 * @throws RuntimeException When the store is not configured or the save fails.
	 *
	 * @spec openspec/specs/payout/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function save(array $row): array {
		$store = $this->payouts();
		try {
			$saved = $this->saveObjectAsArray(
				objectService: $store->objectService,
				register: $store->register,
				schema: $store->schema,
				object: $row
			);
			return ($saved ?? $row);
		} catch (\Throwable $e) {
			throw new RuntimeException('DwangsomUitbetaling persist failed: ' . $e->getMessage());
		}
	}//end save()

	/**
	 * Where payout rows are stored.
	 *
	 * @return MoneyStore The payout schema of the register.
	 *
	 * @throws RuntimeException When the store or the payout schema is not configured.
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function payouts(): MoneyStore {
		[$objectService, $register, $uSchema] = $this->configured(schemaKeys: [MoneyConfigKeys::PAYOUT_SCHEMA]);

		return new MoneyStore(objectService: $objectService, register: $register, schema: $uSchema);
	}//end payouts()

	/**
	 * The object service, the register and the schemas the keys name; a refusal when any is missing.
	 *
	 * @param array<int, string> $schemaKeys The config keys of the schemas, in order.
	 *
	 * @return array<int, mixed> The object service, the register, then one schema per key.
	 *
	 * @throws RuntimeException When the store or a schema is not configured.
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function configured(array $schemaKeys): array {
		$objectService = $this->settingsService->getObjectService();
		$store = [$objectService, (string)$this->settingsService->getConfigValue('register')];
		foreach ($schemaKeys as $key) {
			$store[] = (string)$this->settingsService->getConfigValue($key);
		}

		if ($objectService === null || in_array('', array_slice($store, 1), true) === true) {
			throw new RuntimeException('The accruing amount and payout schemas are not configured');
		}

		return $store;
	}//end configured()

	/**
	 * Read one accruing amount.
	 *
	 * @param MoneyStore $amounts       Where the accruing amounts are stored.
	 * @param string     $calculationId Berekening id.
	 *
	 * @return array<string, mixed>|null The calculation, or null when there is none.
	 *
	 * @throws RuntimeException When the lookup fails.
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function calculation(MoneyStore $amounts, string $calculationId): ?array {
		try {
			return $this->findObjectAsArray(
				objectService: $amounts->objectService,
				register: $amounts->register,
				schema: $amounts->schema,
				id: $calculationId
			);
		} catch (\Throwable $e) {
			throw new RuntimeException('Accruing amount lookup failed: ' . $e->getMessage());
		}
	}//end calculation()

	/**
	 * The payout rows carrying a reference.
	 *
	 * @param MoneyStore $payouts   Where payout rows are stored.
	 * @param string     $reference Payment reference.
	 *
	 * @return array<int, mixed> The rows.
	 *
	 * @throws RuntimeException When the lookup fails.
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function withReference(MoneyStore $payouts, string $reference): array {
		try {
			return $this->searchObjectsAsArrays(
				objectService: $payouts->objectService,
				register: $payouts->register,
				schema: $payouts->schema,
				filters: ['reference' => $reference],
			);
		} catch (\Throwable $e) {
			throw new RuntimeException('DwangsomUitbetaling lookup failed: ' . $e->getMessage());
		}
	}//end withReference()
}//end class
