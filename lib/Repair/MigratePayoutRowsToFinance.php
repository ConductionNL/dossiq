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

use OCA\Dossiq\Service\Money\MoneyConfigKeys;
use OCA\Dossiq\Service\Money\PayoutService;
use OCA\Dossiq\Service\SettingsService;
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
	 * @param SettingsService $settingsService Names the register and the payout schema.
	 * @param PayoutService $payouts Raises a row in the finance app.
	 * @param LoggerInterface $logger Records a row finance refused.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly PayoutService $payouts,
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
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::PAYOUT_SCHEMA);
		if ($objectService === null || $register === '' || $schema === '') {
			return;
		}

		try {
			$rows = (array)$this->runAsSystemIfAvailable(
				objectService: $objectService,
				operation: fn (): array => $this->searchObjectsAsArrays(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					filters: ['_limit' => 10000]
				)
			);
		} catch (Throwable $e) {
			$this->logger->warning('Dossiq: the payout rows could not be read for the move to finance', ['error' => $e->getMessage()]);
			return;
		}

		$moved = 0;
		foreach ($rows as $row) {
			if ($this->moveRow(objectService: $objectService, register: $register, schema: $schema, row: $row) === true) {
				$moved++;
			}
		}

		if ($moved > 0) {
			$output->info('Handed ' . $moved . ' penalty payout(s) to the finance app.');
		}
	}//end run()

	/**
	 * Hand one row to finance and keep the payable id on it.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register The register.
	 * @param string $schema The payout schema.
	 * @param array<string, mixed> $row The stored row.
	 *
	 * @return bool True when finance took the row now.
	 */
	private function moveRow(object $objectService, string $register, string $schema, array $row): bool {
		$status = (string)($row['status'] ?? '');
		if ((string)($row['payableId'] ?? '') !== '' || array_key_exists($status, self::STATES) === false) {
			return false;
		}

		$history = [];
		if (self::STATES[$status] !== '') {
			$history = [
				'historicalState' => self::STATES[$status],
				'paidOn' => (string)($row['actualPaymentDate'] ?? ''),
				'bankReference' => (string)($row['betalingsreferentie'] ?? ''),
			];
		}

		try {
			$raised = $this->payouts->raiseRowInFinance(row: $row, history: $history);
			if ((string)($raised['payableId'] ?? '') === '') {
				return false;
			}

			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
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
}//end class
