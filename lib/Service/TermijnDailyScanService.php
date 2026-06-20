<?php

/**
 * Procest TermijnDailyScanService.
 *
 * Server-authoritative service that performs the daily sweep over all
 * active TermijnInstance rows: computes days-to-deadline, buckets into
 * 14/7/2/0, dispatches escalation through {@see TermijnEscalationService},
 * and flips overdue instances to `overschreden`. Job-level error
 * handling: one bad row never aborts the sweep.
 *
 * Pause-expiry detection: instances in `gepauzeerd` whose pauzeDeadline
 * has passed receive a `pauze-verlopen` event so the caseworker can
 * decide whether to auto-resume or treat as overschreden.
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Daily sweep over active TermijnInstance rows.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class TermijnDailyScanService
{
    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param SettingsService          $settingsService   Settings service.
     * @param TermijnService           $termijnService    TermijnService.
     * @param TermijnEscalationService $escalationService Escalation service.
     * @param LoggerInterface          $logger            Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly TermijnService $termijnService,
        private readonly TermijnEscalationService $escalationService,
        private readonly LoggerInterface $logger,
        private readonly ?DwangsomCalculationService $dwangsomService=null,
    ) {
    }//end __construct()

    /**
     * Run the daily sweep.
     *
     * @param DateTimeImmutable|null $now Optional "now" override for testing.
     *
     * @return array<string, int> Counts: ['scanned', 'overschreden', 'escalated', 'pauseExpired', 'errors']
     *
     * @spec openspec/changes/termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
     */
    public function run(?DateTimeImmutable $now=null): array
    {
        $now    = ($now ?? new DateTimeImmutable());
        $counts = [
            'scanned'      => 0,
            'overschreden' => 0,
            'escalated'    => 0,
            'pauseExpired' => 0,
            'errors'       => 0,
        ];

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return $counts;
        }

        $register = (string) $this->settingsService->getConfigValue('register');
        $schema   = (string) $this->settingsService->getConfigValue('termijn_instance_schema');
        if ($register === '' || $schema === '') {
            return $counts;
        }

        try {
            $rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: []);
        } catch (\Throwable $e) {
            $this->logger->error('Termijn daily scan: list failed', ['error' => $e->getMessage()]);
            return $counts;
        }

        foreach ((array) $rows as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $counts['scanned']++;
            try {
                $this->processInstance($row, $now, $counts);
            } catch (\Throwable $e) {
                $counts['errors']++;
                $this->logger->warning(
                    'Termijn daily scan: row failed',
                    ['id' => (string) ($row['id'] ?? ''), 'error' => $e->getMessage()]
                );
            }
        }

        // Sweep lopend DwangsomBerekeningen (member 06 hook).
        $counts['dwangsomAccrued'] = $this->accrueLopendDwangsomBerekeningen();

        $this->logger->info('Termijn daily scan complete', $counts);
        return $counts;
    }//end run()

    /**
     * Run a calculateDaily() pass over all lopend DwangsomBerekening rows.
     *
     * @return int Number of berekeningen accrued.
     */
    private function accrueLopendDwangsomBerekeningen(): int
    {
        if ($this->dwangsomService === null) {
            return 0;
        }

        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('dwangsom_berekening_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return 0;
        }

        try {
            $rows = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: ['status' => 'lopend']);
        } catch (\Throwable $e) {
            return 0;
        }

        $accrued = 0;
        foreach ((array) $rows as $row) {
            if (is_array($row) === false) {
                continue;
            }

            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }

            try {
                $this->dwangsomService->calculateDaily($id);
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
     * @param array<string, mixed> $row    Instance row.
     * @param DateTimeImmutable    $now    Now.
     * @param array<string, int>   $counts Running counts (by reference).
     *
     * @return void
     */
    private function processInstance(array $row, DateTimeImmutable $now, array &$counts): void
    {
        $status = (string) ($row['status'] ?? '');
        if (in_array($status, ['voltooid', 'overschreden', 'ingetrokken'], true) === true) {
            return;
        }

        $rowId = (string) ($row['id'] ?? '');

        // Pause-expiry detection.
        if ($status === 'gepauzeerd') {
            $pauseEnd = (string) ($row['pauzeDeadline'] ?? '');
            if ($pauseEnd !== '' && $pauseEnd < $now->format('Y-m-d')) {
                $counts['pauseExpired']++;
                $this->termijnService->recordEvent(
                    termijnInstanceId: $rowId,
                    type: 'pauze-verlopen',
                    grondslag: 'AWB 4:5',
                    motivering: 'Pauzetermijn verlopen zonder aanvulling',
                    dagenImpact: 0,
                    tijdstip: $now,
                );
            }

            return;
        }

        $deadline = (string) ($row['einddatumActueel'] ?? '');
        if ($deadline === '') {
            return;
        }

        $deadlineDate = new DateTimeImmutable($deadline);
        $today        = new DateTimeImmutable($now->format('Y-m-d'));
        $diff         = (int) $today->diff($deadlineDate)->days;
        $daysLeft     = ($today > $deadlineDate ? (-1 * $diff) : $diff);

        // Overschrijding.
        if ($daysLeft <= 0 && $status !== 'overschreden') {
            $counts['overschreden']++;
            $this->termijnService->updateTermijnInstance($rowId, ['status' => 'overschreden']);
            $this->termijnService->recordEvent(
                termijnInstanceId: $rowId,
                type: 'overschreden',
                grondslag: 'AWB 4:13',
                motivering: 'Termijn overschreden zonder beschikking',
                dagenImpact: 0,
                tijdstip: $now,
            );
            $row['status'] = 'overschreden';
        }

        // Threshold escalation.
        $bucket = $this->escalationService->bucketFor($daysLeft);
        if ($bucket !== null) {
            // Re-read instance to pick up the just-updated status/notificatiesVerstuurd.
            $latest = $this->termijnService->getTermijnInstance($rowId);
            if ($latest !== null) {
                if ($this->escalationService->notifyThreshold($latest, $bucket) === true) {
                    $counts['escalated']++;
                }
            }
        }
    }//end processInstance()
}//end class
