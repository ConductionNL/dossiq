<?php

/**
 * Dossiq AmountObjectionService.
 *
 * Handles an objection (AWB 7:1) against an accruing amount:
 *   - registerObjection freezes the berekening (status=bezwaar-bevroren),
 *     sets the linked payout to on-hold-bezwaar, and
 *     emits the dwangsom-bezwaar-registered event (its stored name).
 *   - resolveObjection adjusts definitievBedrag + Uitbetaling.bedrag,
 *     restores Uitbetaling.status to voorbereid, and emits
 *     the dwangsom-bezwaar-resolved event (its stored name).
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TermijnService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Objection lifecycle for an accruing amount.
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */
class AmountObjectionService {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings.
	 * @param TermijnService $termService Termijn service for events.
	 * @param LoggerInterface $logger Logger.
	 * @param PayoutService|null $payouts Withdraws a payout from finance while the objection runs, and raises it again after.
	 * @param PayoutReader|null $reader Reads the payouts only finance holds, so an objection reaches them too.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TermijnService $termService,
		private readonly LoggerInterface $logger,
		private readonly ?PayoutService $payouts = null,
		private readonly ?PayoutReader $reader = null,
	) {
	}//end __construct()

	/**
	 * Register an objection against an accruing amount.
	 *
	 * Freezes the berekening (status=bezwaar-bevroren) and puts the
	 * linked uitbetaling on hold.
	 *
	 * @param string $calculationId Accruing amount id.
	 * @param string $basis Legal basis citation.
	 * @param string $rationale Reasoning.
	 *
	 * @return array<string, mixed> The frozen berekening row.
	 *
	 * @throws RuntimeException When the berekening is missing.
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function registerObjection(string $calculationId, string $basis, string $rationale): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$bSchema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA);
		$uSchema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::PAYOUT_SCHEMA);
		$objectService = $this->requireMoneyObjectService(
			objectService: $objectService,
			register: $register,
			bSchema: $bSchema,
			uSchema: $uSchema,
		);

		try {
			$calculation = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $bSchema,
				id: $calculationId
			);
		} catch (\Throwable $e) {
			throw new RuntimeException('Accruing amount lookup failed: ' . $e->getMessage());
		}

		if ($calculation === null) {
			throw new RuntimeException('Accruing amount not found: ' . $calculationId);
		}

		$calculation['status'] = 'objection-bevroren';
		// The stored accrual reads stoppedOn: an objection freezes the amount.
		if ((string)($calculation['stoppedOn'] ?? '') === '') {
			$calculation['stoppedOn'] = (new DateTimeImmutable())->format('Y-m-d');
		}

		try {
			$calculation = ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $bSchema,
				object: $calculation
			) ?? $calculation);
		} catch (\Throwable $e) {
			throw new RuntimeException('Accruing amount could not be saved: ' . $e->getMessage());
		}

		// Move all linked uitbetalingen to on-hold-bezwaar.
		$uitbetalingen = $this->findUitbetalingen(
			objectService: $objectService,
			register: $register,
			uSchema: $uSchema,
			calculationId: $calculationId,
		);

		foreach ($uitbetalingen as $u) {
			try {
				// A payout finance already approved stays with finance: the
				// refusal is logged below and the row keeps its status.
				$u = ($this->payouts?->withdrawFromFinance(row: $u, reason: trim($basis . ' ' . $rationale)) ?? $u);
				$u['status'] = 'on-hold-objection';
				$this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $uSchema, object: $u);
			} catch (\Throwable $e) {
				$this->logger->warning('Bezwaar freeze on uitbetaling failed', ['id' => $u['id'] ?? '', 'error' => $e->getMessage()]);
			}
		}

		$this->withdrawFinanceOnlyPayouts(calculationId: $calculationId, stored: $uitbetalingen, reason: trim($basis . ' ' . $rationale));

		// Record event on termijn.
		$instanceId = (string)($calculation['deadlineInstance'] ?? '');
		if ($instanceId !== '') {
			$this->termService->recordEvent(
				termInstanceId: $instanceId,
				type: 'objection-submitted',
				basis: $basis,
				rationale: $rationale,
				daysImpact: 0,
			);
		}

		$this->logger->info('Objection registered against an accruing amount', ['berekening' => $calculationId]);
		return $calculation;
	}//end registerObjection()

	/**
	 * Resolve a bezwaar with a corrected amount.
	 *
	 * @param string $calculationId Berekening id.
	 * @param int $newAmountCents Corrected amount in EUR cents.
	 * @param string $basis Legal basis.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When berekening missing or amount invalid.
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function resolveObjection(string $calculationId, int $newAmountCents, string $basis): array {
		if ($newAmountCents < 0) {
			throw new RuntimeException('newBedragCents must be >= 0');
		}

		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$bSchema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA);
		$uSchema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::PAYOUT_SCHEMA);
		$objectService = $this->requireMoneyObjectService(
			objectService: $objectService,
			register: $register,
			bSchema: $bSchema,
			uSchema: $uSchema,
		);

		try {
			$calculation = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $bSchema,
				id: $calculationId
			);
		} catch (\Throwable $e) {
			throw new RuntimeException('Accruing amount lookup failed: ' . $e->getMessage());
		}

		if ($calculation === null) {
			throw new RuntimeException('Accruing amount not found: ' . $calculationId);
		}

		$calculation['definitiveAmount'] = $newAmountCents;
		$calculation['status'] = 'completed';
		try {
			$calculation = ($this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $bSchema,
				object: $calculation
			) ?? $calculation);
		} catch (\Throwable $e) {
			throw new RuntimeException('Accruing amount could not be saved: ' . $e->getMessage());
		}

		$uitbetalingen = $this->findUitbetalingen(
			objectService: $objectService,
			register: $register,
			uSchema: $uSchema,
			calculationId: $calculationId,
		);

		foreach ($uitbetalingen as $u) {
			$u['amount'] = $newAmountCents;
			$u['status'] = 'voorbereid';
			try {
				$u = ($this->payouts?->raiseAgain(row: $u) ?? $u);
			} catch (\Throwable $e) {
				$this->logger->warning('Bezwaar resolve: finance refused the payout again', ['id' => $u['id'] ?? '', 'error' => $e->getMessage()]);
			}

			try {
				$this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $uSchema, object: $u);
			} catch (\Throwable $e) {
				$this->logger->warning('Bezwaar resolve on uitbetaling failed', ['id' => $u['id'] ?? '', 'error' => $e->getMessage()]);
			}
		}

		$this->raiseFinanceOnlyPayoutAgain(calculationId: $calculationId, newAmountCents: $newAmountCents, stored: $uitbetalingen);

		$instanceId = (string)($calculation['deadlineInstance'] ?? '');
		if ($instanceId !== '') {
			$this->termService->recordEvent(
				termInstanceId: $instanceId,
				type: 'objection-resolved',
				basis: $basis,
				rationale: 'Bezwaar opgelost; bedrag herzien',
				daysImpact: 0,
			);
		}

		$this->logger->info('Objection against an accruing amount resolved', ['berekening' => $calculationId, 'newBedrag' => $newAmountCents]);
		return $calculation;
	}//end resolveObjection()

	/**
	 * Withdraw the live payouts only finance holds for this calculation.
	 *
	 * A payout finance already approved stays with finance; the refusal is logged.
	 *
	 * @param string $calculationId The calculation.
	 * @param array<int, array<string, mixed>> $stored The payout rows dossiq kept, handled already.
	 * @param string $reason Why it is withdrawn.
	 *
	 * @return void
	 */
	private function withdrawFinanceOnlyPayouts(string $calculationId, array $stored, string $reason): void {
		$kept = array_column($stored, 'payableId');
		foreach (($this->reader?->rowsForCalculation(calculationId: $calculationId) ?? []) as $row) {
			if ($row['status'] === 'withdrawn' || in_array($row['payableId'], $kept, true) === true) {
				continue;
			}

			try {
				$this->payouts?->withdrawFromFinance(row: $row, reason: $reason);
			} catch (\Throwable $e) {
				$this->logger->warning('Bezwaar freeze on a payout finance holds failed', ['reference' => $row['reference'], 'error' => $e->getMessage()]);
			}
		}
	}//end withdrawFinanceOnlyPayouts()

	/**
	 * Raise the payout finance holds for this calculation again with the decided
	 * amount, once: only when dossiq kept no row for it and no live payout is
	 * left, from the last one withdrawn.
	 *
	 * @param string $calculationId The calculation.
	 * @param int $newAmountCents The decided amount.
	 * @param array<int, array<string, mixed>> $stored The payout rows dossiq kept; when any, they were raised again already.
	 *
	 * @return void
	 */
	private function raiseFinanceOnlyPayoutAgain(string $calculationId, int $newAmountCents, array $stored): void {
		$rows = [];
		if ($stored === []) {
			$rows = ($this->reader?->rowsForCalculation(calculationId: $calculationId) ?? []);
		}

		$withdrawn = array_values(array_filter($rows, static fn (array $row): bool => $row['status'] === 'withdrawn'));
		if ($withdrawn === [] || count($withdrawn) !== count($rows)) {
			return;
		}

		$row = end($withdrawn);
		$row['amount'] = $newAmountCents;
		$row['payableId'] = '';
		try {
			$this->payouts?->raiseAgain(row: $row);
		} catch (\Throwable $e) {
			$this->logger->warning('Bezwaar resolve: finance refused the payout again', ['reference' => $row['reference'], 'error' => $e->getMessage()]);
		}
	}//end raiseFinanceOnlyPayoutAgain()

	/**
	 * Assert the register and the money schemas are configured and OpenRegister is
	 * available, narrowing the object service to a non-null value.
	 *
	 * @param object|null $objectService Resolved OpenRegister object service.
	 * @param string $register Register identifier.
	 * @param string $bSchema Accruing amount schema identifier.
	 * @param string $uSchema Payout schema identifier.
	 *
	 * @return object The available object service.
	 *
	 * @throws RuntimeException When any part of the configuration is missing.
	 */
	private function requireMoneyObjectService(
		?object $objectService,
		string $register,
		string $bSchema,
		string $uSchema,
	): object {
		if ($objectService === null || $register === '' || $bSchema === '' || $uSchema === '') {
			throw new RuntimeException('The accruing amount and payout schemas are not configured');
		}

		return $objectService;
	}//end requireMoneyObjectService()

	/**
	 * Load the uitbetalingen linked to a berekening, tolerating lookup failures.
	 *
	 * @param object $objectService OpenRegister object service.
	 * @param string $register Register identifier.
	 * @param string $uSchema Payout schema identifier.
	 * @param string $calculationId Accruing amount id.
	 *
	 * @return array<int, array<string, mixed>> The linked uitbetalingen.
	 */
	private function findUitbetalingen(
		object $objectService,
		string $register,
		string $uSchema,
		string $calculationId,
	): array {
		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $uSchema,
				filters: ['penaltyPaymentCalculation' => $calculationId]
			);
		} catch (\Throwable $e) {
			// Lookup failures must not block the bezwaar transition.
			return [];
		}
	}//end findUitbetalingen()
}//end class
