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
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use DateTimeImmutable;
use OCA\Dossiq\Kernel\Contracts\AmountObjectionServiceInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TermijnService;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Objection lifecycle for an accruing amount.
 *
 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
 */
class AmountObjectionService implements AmountObjectionServiceInterface {
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
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function registerObjection(string $calculationId, string $basis, string $rationale): array {
		$run = $this->openCalculation(calculationId: $calculationId);

		$run->calculation['status'] = 'objection-bevroren';
		// The stored accrual reads stoppedOn: an objection freezes the amount.
		if ((string)($run->calculation['stoppedOn'] ?? '') === '') {
			$run->calculation['stoppedOn'] = (new DateTimeImmutable())->format('Y-m-d');
		}

		$this->saveCalculation(run: $run);

		// Move all linked uitbetalingen to on-hold-bezwaar.
		$run->basis = $basis;
		$run->rationale = $rationale;
		$uitbetalingen = $this->findUitbetalingen(run: $run);
		foreach ($uitbetalingen as $u) {
			$this->holdPayout(run: $run, payout: $u);
		}

		$this->withdrawFinanceOnlyPayouts(calculationId: $calculationId, stored: $uitbetalingen, reason: $run->reason());

		$this->recordTermEvent(run: $run, type: 'objection-submitted');

		$this->logger->info('Objection registered against an accruing amount', ['berekening' => $calculationId]);
		return $run->calculation;
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
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-10-bezwaar-rest-api/tasks.md
	 */
	public function resolveObjection(string $calculationId, int $newAmountCents, string $basis): array {
		if ($newAmountCents < 0) {
			throw new RuntimeException('newBedragCents must be >= 0');
		}

		$run = $this->openCalculation(calculationId: $calculationId);

		$run->calculation['definitiveAmount'] = $newAmountCents;
		$run->calculation['status'] = 'completed';
		$this->saveCalculation(run: $run);

		$uitbetalingen = $this->findUitbetalingen(run: $run);
		foreach ($uitbetalingen as $u) {
			$u['amount'] = $newAmountCents;
			$u['status'] = 'voorbereid';
			$this->raisePayoutAgain(run: $run, payout: $u);
		}

		$this->raiseFinanceOnlyPayoutAgain(calculationId: $calculationId, newAmountCents: $newAmountCents, stored: $uitbetalingen);

		$run->basis = $basis;
		$run->rationale = 'Bezwaar opgelost; bedrag herzien';
		$this->recordTermEvent(run: $run, type: 'objection-resolved');

		$this->logger->info('Objection against an accruing amount resolved', ['berekening' => $calculationId, 'newBedrag' => $newAmountCents]);
		return $run->calculation;
	}//end resolveObjection()

	/**
	 * Read the configuration and the accruing amount the objection is about.
	 *
	 * @param string $calculationId Accruing amount id.
	 *
	 * @return ObjectionRun
	 *
	 * @throws RuntimeException When the store is not configured, or the amount cannot be read or is missing.
	 */
	private function openCalculation(string $calculationId): ObjectionRun {
		$run = new ObjectionRun();
		$objectService = $this->settingsService->getObjectService();
		$run->register = (string)$this->settingsService->getConfigValue('register');
		$run->amountSchema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA);
		$run->payoutSchema = (string)$this->settingsService->getConfigValue(MoneyConfigKeys::PAYOUT_SCHEMA);
		$run->objectService = $this->requireMoneyObjectService(objectService: $objectService, run: $run);
		$run->calculationId = $calculationId;
		$run->calculation = $this->storedCalculation(run: $run);

		return $run;
	}//end openCalculation()

	/**
	 * The stored accruing amount.
	 *
	 * @param ObjectionRun $run The objection step.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws RuntimeException When the amount cannot be read or is missing.
	 */
	private function storedCalculation(ObjectionRun $run): array {
		try {
			$calculation = $this->findObjectAsArray(
				objectService: $run->objectService,
				register: $run->register,
				schema: $run->amountSchema,
				id: $run->calculationId
			);
		} catch (\Throwable $e) {
			throw new RuntimeException('Accruing amount lookup failed: ' . $e->getMessage());
		}

		if ($calculation === null) {
			throw new RuntimeException('Accruing amount not found: ' . $run->calculationId);
		}

		return $calculation;
	}//end storedCalculation()

	/**
	 * Save the accruing amount as it now stands; the run then holds what was stored.
	 *
	 * @param ObjectionRun $run The objection step.
	 *
	 * @return void
	 *
	 * @throws RuntimeException When the amount could not be saved.
	 */
	private function saveCalculation(ObjectionRun $run): void {
		try {
			$run->calculation = ($this->saveObjectAsArray(
				objectService: $run->objectService,
				register: $run->register,
				schema: $run->amountSchema,
				object: $run->calculation
			) ?? $run->calculation);
		} catch (\Throwable $e) {
			throw new RuntimeException('Accruing amount could not be saved: ' . $e->getMessage());
		}
	}//end saveCalculation()

	/**
	 * Put one stored payout on hold for the objection; a failure is logged.
	 *
	 * A payout finance already approved stays with finance: the refusal is
	 * logged and the row keeps its status.
	 *
	 * @param ObjectionRun         $run    The objection step, with its basis and rationale.
	 * @param array<string, mixed> $payout The payout row.
	 *
	 * @return void
	 */
	private function holdPayout(ObjectionRun $run, array $payout): void {
		$u = $payout;
		try {
			$u = ($this->payouts?->withdrawFromFinance(row: $u, reason: $run->reason()) ?? $u);
			$u['status'] = 'on-hold-objection';
			$this->savePayout(run: $run, payout: $u);
		} catch (\Throwable $e) {
			$this->logger->warning('Bezwaar freeze on uitbetaling failed', ['id' => $u['id'] ?? '', 'error' => $e->getMessage()]);
		}
	}//end holdPayout()

	/**
	 * Offer one stored payout to finance again and store it; each failure is logged.
	 *
	 * @param ObjectionRun         $run    The objection step.
	 * @param array<string, mixed> $payout The payout row with the decided amount.
	 *
	 * @return void
	 */
	private function raisePayoutAgain(ObjectionRun $run, array $payout): void {
		try {
			$payout = ($this->payouts?->raiseAgain(row: $payout) ?? $payout);
		} catch (\Throwable $e) {
			$this->logger->warning('Bezwaar resolve: finance refused the payout again', ['id' => $payout['id'] ?? '', 'error' => $e->getMessage()]);
		}

		try {
			$this->savePayout(run: $run, payout: $payout);
		} catch (\Throwable $e) {
			$this->logger->warning('Bezwaar resolve on uitbetaling failed', ['id' => $payout['id'] ?? '', 'error' => $e->getMessage()]);
		}
	}//end raisePayoutAgain()

	/**
	 * Store one payout row.
	 *
	 * @param ObjectionRun         $run    The objection step.
	 * @param array<string, mixed> $payout The payout row.
	 *
	 * @return void
	 */
	private function savePayout(ObjectionRun $run, array $payout): void {
		$this->saveObjectAsArray(objectService: $run->objectService, register: $run->register, schema: $run->payoutSchema, object: $payout);
	}//end savePayout()

	/**
	 * Record the objection event on the amount's deadline instance, when it has one.
	 *
	 * @param ObjectionRun $run  The objection step, with its basis and rationale.
	 * @param string       $type The event type.
	 *
	 * @return void
	 */
	private function recordTermEvent(ObjectionRun $run, string $type): void {
		$instanceId = (string)($run->calculation['deadlineInstance'] ?? '');
		if ($instanceId === '') {
			return;
		}

		$this->termService->recordEvent(
			termInstanceId: $instanceId,
			type: $type,
			basis: $run->basis,
			rationale: $run->rationale,
			daysImpact: 0,
		);
	}//end recordTermEvent()

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
	 * @param object|null  $objectService Resolved OpenRegister object service.
	 * @param ObjectionRun $run           The objection step, with its register and schemas read.
	 *
	 * @return object The available object service.
	 *
	 * @throws RuntimeException When any part of the configuration is missing.
	 */
	private function requireMoneyObjectService(?object $objectService, ObjectionRun $run): object {
		if ($objectService === null || $run->register === '' || $run->amountSchema === '' || $run->payoutSchema === '') {
			throw new RuntimeException('The accruing amount and payout schemas are not configured');
		}

		return $objectService;
	}//end requireMoneyObjectService()

	/**
	 * Load the uitbetalingen linked to a berekening, tolerating lookup failures.
	 *
	 * @param ObjectionRun $run The objection step.
	 *
	 * @return array<int, array<string, mixed>> The linked uitbetalingen.
	 */
	private function findUitbetalingen(ObjectionRun $run): array {
		try {
			return $this->searchObjectsAsArrays(
				objectService: $run->objectService,
				register: $run->register,
				schema: $run->payoutSchema,
				filters: ['penaltyPaymentCalculation' => $run->calculationId]
			);
		} catch (\Throwable $e) {
			// Lookup failures must not block the bezwaar transition.
			return [];
		}
	}//end findUitbetalingen()
}//end class
