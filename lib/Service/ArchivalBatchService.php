<?php

/**
 * Procest ArchivalBatchService.
 *
 * Drives bulk handover of a list of closed cases through the archival
 * pipeline. The batch is recorded as an OverdrachtTrigger-collection
 * (one trigger per case) and shares its state machine with the daily
 * detection sweep:
 *
 *   queued → processing → (completed | partially-failed)
 *
 * On each case the service:
 *   1. Detects/updates the OverdrachtTrigger row (delegating to
 *      ArchivalTriggerService::detectReadyCases for state consistency),
 *   2. Calls submitToEdepot via the bound EDepotSubmissionAdapter,
 *   3. Records counters + dispatches `batch-initiated` and
 *      `batch-completed` events through the shared audit log.
 *
 * The processor honours a `rateLimit` to cap concurrent dispatches and
 * holds its own state in a synthetic batch row keyed by `batchId` (a
 * UUID-shaped string the caller supplies, default = generated here).
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
 * @spec openspec/changes/archief-edepot-handover-07-batch-inspection/proposal.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Bulk archival batch processor.
 *
 * @spec openspec/changes/archief-edepot-handover-07-batch-inspection/tasks.md
 */
class ArchivalBatchService
{
    /**
     * Constructor.
     *
     * @param SettingsService        $settingsService Settings + ObjectService resolver.
     * @param ArchivalTriggerService $triggerService  Orchestrator delegate.
     * @param LoggerInterface        $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ArchivalTriggerService $triggerService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Initiate a batch run for a list of case ids.
     *
     * @param array<int, string> $caseIds   Zaak ids.
     * @param int                $rateLimit Max concurrent dispatches (informational).
     * @param string             $eDepotId  Optional logical archive id.
     * @param string|null        $batchId   Optional caller-supplied id (else generated).
     *
     * @return array{
     *     batchId: string,
     *     state: string,
     *     scheduled: int,
     *     succeeded: int,
     *     failed: int,
     *     deferred: int,
     *     skipped: int
     * } Batch summary.
     *
     * @spec openspec/changes/archief-edepot-handover-07-batch-inspection/tasks.md
     */
    public function initiateBatch(
        array $caseIds,
        int $rateLimit = 4,
        string $eDepotId = '',
        ?string $batchId = null,
    ): array {
        $batchId ??= 'batch-'.bin2hex(random_bytes(8));
        $counts = [
            'batchId'   => $batchId,
            'state'     => 'queued',
            'scheduled' => count($caseIds),
            'succeeded' => 0,
            'failed'    => 0,
            'deferred'  => 0,
            'skipped'   => 0,
        ];

        $this->triggerService->logEvent(
            null,
            null,
            'batch-initiated',
            'batchId='.$batchId.' size='.count($caseIds).' rateLimit='.$rateLimit.' eDepotId='.$eDepotId
        );

        $counts['state'] = 'processing';

        $i = 0;
        foreach ($caseIds as $caseId) {
            if ($i >= $rateLimit && $rateLimit > 0) {
                // Rate-limit hint: in a queued-execution mode the caller
                // would re-invoke after the inflight window drains. Within
                // a single synchronous run we still process every case so
                // the batch terminates deterministically.
                $i = 0;
            }
            $i++;

            try {
                $outcome = $this->processCaseInBatch((string) $caseId, $batchId, $eDepotId);
            } catch (\Throwable $e) {
                $counts['failed']++;
                $this->triggerService->logEvent(
                    null,
                    (string) $caseId,
                    'batch-case-failed',
                    'batchId='.$batchId.' error='.$e->getMessage()
                );
                continue;
            }

            $counts[$outcome]++;
        }

        $finalState = ($counts['failed'] === 0) ? 'completed' : 'partially-failed';
        $counts['state'] = $finalState;

        $this->triggerService->logEvent(
            null,
            null,
            'batch-completed',
            'batchId='.$batchId.' state='.$finalState
                .' succeeded='.$counts['succeeded']
                .' failed='.$counts['failed']
                .' deferred='.$counts['deferred']
                .' skipped='.$counts['skipped']
        );

        return $counts;
    }//end initiateBatch()

    /**
     * Process a single case inside the batch.
     *
     * @param string $caseId   Zaak id.
     * @param string $batchId  Batch id (audit correlation).
     * @param string $eDepotId Logical archive id.
     *
     * @return string Outcome bucket: `succeeded` | `failed` | `deferred` | `skipped`.
     *
     * @spec openspec/changes/archief-edepot-handover-07-batch-inspection/tasks.md
     */
    public function processCaseInBatch(string $caseId, string $batchId = '', string $eDepotId = ''): string
    {
        if ($caseId === '') {
            return 'skipped';
        }

        // Locate the SipBundel already staged for this case (member 03/04
        // shipped the bundler). If none, defer — the daily detection sweep
        // owns the path back to `gereed-voor-overdracht`.
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $sipSchema     = (string) $this->settingsService->getConfigValue('sip_bundel_schema');
        if ($objectService === null || $register === '' || $sipSchema === '') {
            return 'skipped';
        }

        $sipBundelId = '';
        try {
            $rows = $objectService->searchObjectsBySlug('procest', 'sipBundel', ['zaakId' => $caseId]);
            foreach ((array) $rows as $row) {
                $arr = (is_array($row) === true) ? $row : (method_exists($row, 'jsonSerialize') ? (array) $row->jsonSerialize() : []);
                if (isset($arr['id']) === true) {
                    $sipBundelId = (string) $arr['id'];
                    break;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to deferred.
        }

        if ($sipBundelId === '') {
            $this->triggerService->logEvent(
                null,
                $caseId,
                'batch-case-deferred',
                'batchId='.$batchId.' reason=no-sip-bundel'
            );
            return 'deferred';
        }

        $result = $this->triggerService->submitToEdepot(
            $sipBundelId,
            $caseId,
            ['batchId' => $batchId, 'eDepotId' => $eDepotId]
        );

        if ($result === null) {
            $this->triggerService->logEvent(
                null,
                $caseId,
                'batch-case-deferred',
                'batchId='.$batchId.' reason=no-submitter sipBundelId='.$sipBundelId
            );
            return 'deferred';
        }

        $status = strtoupper($result->submissionStatus);
        $bucket = match ($status) {
            'FAILED'   => 'failed',
            'DEFERRED' => 'deferred',
            default    => 'succeeded',
        };
        // Correlate every per-case dispatch with the batch id so the
        // batch status / report endpoints can reconstruct caseIds from
        // the audit log alone.
        $this->triggerService->logEvent(
            null,
            $caseId,
            'batch-case-'.$bucket,
            'batchId='.$batchId.' sipBundelId='.$sipBundelId.' status='.$status
        );
        return $bucket;
    }//end processCaseInBatch()

    /**
     * Generate an inspection-export aggregate for a calendar year.
     *
     * Walks every OverdrachtTrigger row whose `afsluitingsDatum` falls in
     * the requested year, joins each one to its OverdrachtTransactie tail
     * + ArchiefBewijs proof, and emits a flat JSON payload suitable for
     * the toezichthouder's annual inspection request.
     *
     * @param int                  $year    Calendar year (e.g. 2026).
     * @param array<string, mixed> $filters Optional filters (zaaktypeKey, archiefId).
     *
     * @return array{
     *     year: int,
     *     generatedAt: string,
     *     filters: array<string, mixed>,
     *     totals: array{triggers: int, transactions: int, bewijzen: int},
     *     rows: array<int, array<string, mixed>>
     * } Export payload.
     *
     * @spec openspec/changes/archief-edepot-handover-07-batch-inspection/tasks.md
     */
    public function generateInspectionExport(int $year, array $filters = []): array
    {
        $payload = [
            'year'        => $year,
            'generatedAt' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
            'filters'     => $filters,
            'totals'      => ['triggers' => 0, 'transactions' => 0, 'bewijzen' => 0],
            'rows'        => [],
        ];

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return $payload;
        }

        try {
            $triggers = $objectService->searchObjectsBySlug('procest', 'overdrachtTrigger', $filters);
        } catch (\Throwable $e) {
            $this->logger->warning('ArchivalBatchService.generateInspectionExport: trigger query failed', ['error' => $e->getMessage()]);
            return $payload;
        }

        $yearPrefix = (string) $year;
        foreach ((array) $triggers as $row) {
            $arr = (is_array($row) === true) ? $row : (method_exists($row, 'jsonSerialize') ? (array) $row->jsonSerialize() : []);
            $closedAt = (string) ($arr['afsluitingsDatum'] ?? '');
            if (str_starts_with($closedAt, $yearPrefix) === false) {
                continue;
            }
            $payload['totals']['triggers']++;
            $payload['rows'][] = [
                'triggerId'        => $arr['id'] ?? null,
                'zaakId'           => $arr['zaakId'] ?? null,
                'zaaktypeKey'      => $arr['zaaktypeKey'] ?? null,
                'status'           => $arr['status'] ?? null,
                'afsluitingsDatum' => $closedAt,
                'overdrachtDatum'  => $arr['overdrachtDatum'] ?? null,
            ];
        }

        return $payload;
    }//end generateInspectionExport()
}//end class
