<?php

/**
 * Dossiq DeadlineDailyScanService.
 *
 * Server-authoritative service that performs the daily sweep over all
 * active TermijnInstance rows: computes days-to-deadline, buckets into
 * 14/7/2/0, dispatches escalation through {@see DeadlineEscalationService},
 * and flips overdue instances to `overschreden`. Job-level error
 * handling: one bad row never aborts the sweep.
 *
 * Pause-expiry detection: instances in `gepauzeerd` whose pauzeDeadline
 * has passed receive a `pauze-verlopen` event so the caseworker can
 * decide whether to auto-resume or treat as overschreden.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Daily sweep over active TermijnInstance rows.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 *
 * @spec openspec/specs/termijn-escalation/spec.md
 */
class DeadlineDailyScanService {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service.
	 * @param TermijnService $termService TermijnService.
	 * @param DeadlineEscalationService $escalationService Escalation service.
	 * @param LoggerInterface $logger Logger.
	 * @param DwangsomCalculationService|null $penaltyService Dwangsom calculation service.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TermijnService $termService,
		private readonly DeadlineEscalationService $escalationService,
		private readonly LoggerInterface $logger,
		private readonly ?DwangsomCalculationService $penaltyService = null,
	) {
	}//end __construct()

	/**
	 * Run the daily sweep.
	 *
	 * @param DateTimeImmutable|null $now Optional "now" override for testing.
	 *
	 * @return array<string, int> Counts: ['scanned', 'exceeded', 'escalated', 'pauseExpired', 'errors']
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
	 */
	public function run(?DateTimeImmutable $now = null): array {
		$now = ($now ?? new DateTimeImmutable());
		$counts = [
			'scanned' => 0,
			'exceeded' => 0,
			'escalated' => 0,
			'pauseExpired' => 0,
			'errors' => 0,
		];

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return $counts;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_instance_schema');
		if ($register === '' || $schema === '') {
			return $counts;
		}

		try {
			$rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: []);
		} catch (\Throwable $e) {
			$this->logger->error('Termijn daily scan: list failed', ['error' => $e->getMessage()]);
			return $counts;
		}

		foreach ($rows as $row) {
			$counts['scanned']++;
			try {
				$this->processInstance(row: $row, now: $now, counts: $counts);
			} catch (\Throwable $e) {
				$counts['errors']++;
				$this->logger->warning(
					'Termijn daily scan: row failed',
					['id' => (string)($row['id'] ?? ''), 'error' => $e->getMessage()]
				);
			}
		}

		// Sweep lopend DwangsomBerekeningen (member 06 hook).
		$counts['dwangsomAccrued'] = $this->accrueLopendPenaltyPaymentBerekeningen();

		$this->logger->info('Termijn daily scan complete', $counts);
		return $counts;
	}//end run()

	/**
	 * Run a calculateDaily() pass over all lopend DwangsomBerekening rows.
	 *
	 * @return int Number of berekeningen accrued.
	 */
	private function accrueLopendPenaltyPaymentBerekeningen(): int {
		if ($this->penaltyService === null) {
			return 0;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('dwangsom_berekening_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return 0;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['status' => 'lopend']
			);
		} catch (\Throwable $e) {
			return 0;
		}

		$accrued = 0;
		foreach ($rows as $row) {
			$id = (string)($row['id'] ?? '');
			if ($id === '') {
				continue;
			}

			try {
				$this->penaltyService->calculateDaily($id);
				$accrued++;
			} catch (\Throwable $e) {
				$this->logger->warning('Dwangsom accrual row failed', ['id' => $id, 'error' => $e->getMessage()]);
			}
		}

		return $accrued;
	}//end accrueLopendDwangsomBerekeningen()

	/**
	 * Process a single TermijnInstance row.
	 *
	 * @param array<string, mixed> $row Instance row.
	 * @param DateTimeImmutable $now Now.
	 * @param array<string, int> $counts Running counts (by reference).
	 *
	 * @return void
	 */
	private function processInstance(array $row, DateTimeImmutable $now, array &$counts): void {
		$status = (string)($row['status'] ?? '');
		if (in_array($status, ['completed', 'exceeded', 'withdrawn'], true) === true) {
			return;
		}

		$rowId = (string)($row['id'] ?? '');

		// Pause-expiry detection.
		if ($status === 'paused') {
			$this->handlePauseExpiry(row: $row, rowId: $rowId, now: $now, counts: $counts);
			return;
		}

		$deadline = (string)($row['endDateCurrent'] ?? '');
		if ($deadline === '') {
			return;
		}

		$daysLeft = $this->calculateDaysLeft(deadline: $deadline, now: $now);

		// Overschrijding.
		if ($daysLeft <= 0 && $status !== 'exceeded') {
			$this->recordOverschrijding(rowId: $rowId, now: $now, counts: $counts);
			$row['status'] = 'exceeded';
		}

		// Threshold escalation.
		$this->escalateThreshold(rowId: $rowId, daysLeft: $daysLeft, counts: $counts);
	}//end processInstance()

	/**
	 * Emit a `pauze-verlopen` event when a paused instance ran past its pause deadline.
	 *
	 * @param array<string, mixed> $row Instance row.
	 * @param string $rowId Instance identifier.
	 * @param DateTimeImmutable $now Now.
	 * @param array<string, int> $counts Running counts (by reference).
	 *
	 * @return void
	 */
	private function handlePauseExpiry(array $row, string $rowId, DateTimeImmutable $now, array &$counts): void {
		$pauseEnd = (string)($row['pauseDeadline'] ?? '');
		if ($pauseEnd !== '' && $pauseEnd < $now->format('Y-m-d')) {
			$counts['pauseExpired']++;
			$this->termService->recordEvent(
				termInstanceId: $rowId,
				type: 'pause-expired',
				basis: 'AWB 4:5',
				rationale: 'Pauzetermijn verlopen zonder aanvulling',
				daysImpact: 0,
				moment: $now,
			);
		}
	}//end handlePauseExpiry()

	/**
	 * Compute the signed number of days left until a deadline.
	 *
	 * @param string $deadline Deadline date string.
	 * @param DateTimeImmutable $now Now.
	 *
	 * @return int Positive when the deadline lies ahead, negative when it has passed.
	 */
	private function calculateDaysLeft(string $deadline, DateTimeImmutable $now): int {
		$deadlineDate = new DateTimeImmutable($deadline);
		$today = new DateTimeImmutable($now->format('Y-m-d'));
		$diff = (int)$today->diff($deadlineDate)->days;
		if ($today > $deadlineDate) {
			return (-1 * $diff);
		}

		return $diff;
	}//end calculateDaysLeft()

	/**
	 * Flip an instance to `overschreden` and record the accompanying event.
	 *
	 * @param string $rowId Instance identifier.
	 * @param DateTimeImmutable $now Now.
	 * @param array<string, int> $counts Running counts (by reference).
	 *
	 * @return void
	 */
	private function recordOverschrijding(string $rowId, DateTimeImmutable $now, array &$counts): void {
		$counts['exceeded']++;
		$this->termService->updateTermijnInstance($rowId, ['status' => 'exceeded']);
		$this->termService->recordEvent(
			termInstanceId: $rowId,
			type: 'exceeded',
			basis: 'AWB 4:13',
			rationale: 'Termijn overschreden zonder beschikking',
			daysImpact: 0,
			moment: $now,
		);
	}//end recordOverschrijding()

	/**
	 * Dispatch threshold escalation for an instance when its days-left falls in a bucket.
	 *
	 * @param string $rowId Instance identifier.
	 * @param int $daysLeft Signed days left until the deadline.
	 * @param array<string, int> $counts Running counts (by reference).
	 *
	 * @return void
	 */
	private function escalateThreshold(string $rowId, int $daysLeft, array &$counts): void {
		$bucket = $this->escalationService->bucketFor($daysLeft);
		if ($bucket === null) {
			return;
		}

		// Re-read instance to pick up the just-updated status/notificatiesVerstuurd.
		$latest = $this->termService->getTermijnInstance($rowId);
		if ($latest === null) {
			return;
		}

		if ($this->escalationService->notifyThreshold($latest, $bucket) === true) {
			$counts['escalated']++;
		}
	}//end escalateThreshold()
}//end class
