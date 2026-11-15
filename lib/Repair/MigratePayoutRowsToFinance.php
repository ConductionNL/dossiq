<?php

/**
 * Dossiq Migrate Payout Rows To Finance Repair Step
 *
 * Dossiq kept a row per penalty payout (`dwangsomUitbetaling`). The payout now
 * lives in the finance app (Ruben, 23 Oct). This step hands every row finance
 * does not hold yet to shillinq, under its own reference and linked to its
 * case, and writes the payable id back on the row. The row stays as it was,
 * so nothing stored is lost and the step can run again.
 *
 * A prepared row becomes a live payout finance still has to approve. A row
 * that was approved, paid or rejected goes over as a historical payout in that
 * state. A row under objection waits: the objection decides its amount.
 *
 * IT NEVER THROWS. A repair step that throws aborts the upgrade; a row the
 * finance app refuses is logged and left for the next run.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\Contracts\PayoutServiceInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Money\MoneyConfigKeys;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hands the payout rows dossiq kept to the finance app.
 *
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
 */
class MigratePayoutRowsToFinance implements IRepairStep {
	use SearchesObjects;

	/**
	 * Row status => the state the payout reached in finance; '' for a live payout.
	 * A status not listed waits.
	 *
	 * @var array<string, string>
	 */
	private const STATES = [
		'voorbereid' => '',
		'in-handling' => 'approved',
		'paid' => 'paid',
		'rejected' => 'rejected',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settingsService Names the register and the payout schema.
	 * @param PayoutServiceInterface $payouts Raises a row in the finance app.
	 * @param LoggerInterface $logger Records a row finance refused.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly PayoutServiceInterface $payouts,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name, as `occ upgrade` prints it.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	public function getName(): string {
		return 'Hand the penalty payouts dossiq kept to the finance app';
	}//end getName()

	/**
	 * Hand every row finance does not hold yet to the finance app.
	 *
	 * @param IOutput $output Upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	public function run(IOutput $output): void {
		$target = $this->payoutTarget();
		if ($target === null) {
			return;
		}

		[$objectService, $register, $schema] = $target;
		$store = new PayoutRowStore(objectService: $objectService, register: $register, schema: $schema);
		try {
			$rows = $this->readRows(store: $store);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the payout rows could not be read for the move to finance', ['error' => $e->getMessage()]);
			return;
		}

		$moved = $this->moveAll(store: $store, rows: $rows);
		$this->reportMoved(output: $output, moved: $moved);
	}//end run()

	/**
	 * The object service, register and payout schema, or null when one is missing.
	 *
	 * @return array{0: object, 1: string, 2: string}|null The target.
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	private function payoutTarget(): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::PAYOUT_SCHEMA);
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		return [$objectService, $register, $schema];
	}//end payoutTarget()

	/**
	 * Read every payout row, elevated.
	 *
	 * @param PayoutRowStore $store The object service, register and payout schema.
	 *
	 * @return array<int|string, mixed> The rows.
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	private function readRows(PayoutRowStore $store): array {
		return (array)$this->runAsSystemIfAvailable(
			objectService: $store->objectService,
			operation: fn (): array => $this->searchObjectsAsArrays(
				objectService: $store->objectService,
				register: $store->register,
				schema: $store->schema,
				filters: ['_limit' => 10000]
			)
		);
	}//end readRows()

	/**
	 * Hand every row to finance.
	 *
	 * @param PayoutRowStore           $store The object service, register and payout schema.
	 * @param array<int|string, mixed> $rows  The stored rows.
	 *
	 * @return int How many rows were handed over.
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	private function moveAll(PayoutRowStore $store, array $rows): int {
		$moved = 0;
		foreach ($rows as $row) {
			if ($this->moveRow(store: $store, row: $row) === true) {
				$moved++;
			}
		}

		return $moved;
	}//end moveAll()

	/**
	 * Say how many rows were handed over, when any were.
	 *
	 * @param IOutput $output Upgrade output.
	 * @param int     $moved  How many rows were handed over.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-004)
	 */
	private function reportMoved(IOutput $output, int $moved): void {
		if ($moved > 0) {
			$output->info('Handed ' . $moved . ' penalty payout(s) to the finance app.');
		}
	}//end reportMoved()

	/**
	 * Hand one row to finance and keep the payable id on it.
	 *
	 * @param PayoutRowStore $store The object service, register and payout schema.
	 * @param array<string, mixed> $row The stored row.
	 *
	 * @return bool True when finance took the row now.
	 */
	private function moveRow(PayoutRowStore $store, array $row): bool {
		$status = (string)($row['status'] ?? '');
		if ((string)($row['payableId'] ?? '') !== '' || array_key_exists($status, self::STATES) === false) {
			return false;
		}

		$history = $this->historyOf(row: $row, status: $status);

		try {
			$raised = $this->payouts->raiseRowInFinance(row: $row, history: $history);
			if ((string)($raised['payableId'] ?? '') === '') {
				return false;
			}

			$this->patchObjectAsArray(
				objectService: $store->objectService,
				register: $store->register,
				schema: $store->schema,
				id: (string)($row['id'] ?? ''),
				changes: ['payableId' => $raised['payableId']],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the finance app refused penalty payout {reference}; it stays for the next run',
				['reference' => (string)($row['reference'] ?? ''), 'error' => $e->getMessage()]
			);
			return false;
		}//end try

		return true;
	}//end moveRow()

	/**
	 * The historical payment state a row carries to finance, or none for an open row.
	 *
	 * @param array<string, mixed> $row    The stored row.
	 * @param string               $status The row's status.
	 *
	 * @return array<string, string> The history.
	 */
	private function historyOf(array $row, string $status): array {
		$history = [];
		if (self::STATES[$status] !== '') {
			$history = [
				'historicalState' => self::STATES[$status],
				'paidOn' => (string)($row['actualPaymentDate'] ?? ''),
				'bankReference' => (string)($row['betalingsreferentie'] ?? ''),
			];
		}

		return $history;
	}//end historyOf()
}//end class
