<?php

/**
 * Procest ArchivalSubmissionRetryService.
 *
 * Walks the OverdrachtTransactie queue and replays SIP submissions that
 * previously failed. Each unsuccessful attempt is held back with an
 * exponential-backoff schedule (1m → 5m → 30m → 2h → 8h); the fifth
 * consecutive failure is escalated to the DIV audit trail. Each replay
 * call appends a NEW OverdrachtTransactie row with an incremented
 * `attemptNumber`, so the audit log remains append-only and the SipBundel
 * never carries mutable retry state.
 *
 * The service is transport-agnostic: it delegates the dispatch to the
 * already-wired EDepotSubmissionAdapter on ArchivalTriggerService, so the
 * same code path runs against the dormant LogEDepotSubmissionAdapter today
 * and the openconnector-backed live adapter when it is bound.
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
 * @spec openspec/changes/archief-edepot-handover-05-sip-submission/proposal.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Exponential-backoff retry orchestrator for failed e-Depot submissions.
 *
 * @spec openspec/changes/archief-edepot-handover-05-sip-submission/tasks.md
 */
class ArchivalSubmissionRetryService
{
    use SearchesObjects;

    /**
     * Backoff schedule in seconds, indexed by previous attempt number.
     *
     * 1m → 5m → 30m → 2h → 8h. Attempt #5 onward triggers escalation
     * instead of further backoff.
     *
     * @var array<int, int>
     */
    public const BACKOFF_SECONDS = [
        1 => 60,
        2 => 300,
        3 => 1800,
        4 => 7200,
        5 => 28800,
    ];

    /**
     * After this many attempts the transaction is escalated to the DIV
     * audit channel rather than re-tried.
     */
    public const ESCALATION_THRESHOLD = 5;

    /**
     * Constructor.
     *
     * @param SettingsService        $settingsService Settings + ObjectService resolver.
     * @param ArchivalTriggerService $triggerService  Orchestrator that owns the adapter.
     * @param LoggerInterface        $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ArchivalTriggerService $triggerService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Process the retry queue.
     *
     * @param int|null $now Optional epoch override (testing).
     *
     * @return array{
     *     scanned: int,
     *     retried: int,
     *     skipped_backoff: int,
     *     escalated: int,
     *     errors: int
     * } Counters describing what happened on this sweep.
     *
     * @spec openspec/changes/archief-edepot-handover-05-sip-submission/tasks.md
     */
    public function processRetryQueue(?int $now = null): array
    {
        $counts = [
            'scanned'         => 0,
            'retried'         => 0,
            'skipped_backoff' => 0,
            'escalated'       => 0,
            'errors'          => 0,
        ];

        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('overdracht_transactie_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            $this->logger->info('ArchivalSubmissionRetryService: queue offline (no register/schema)');
            return $counts;
        }

        try {
            $rows = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                filters: ['status' => 'failed'],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('ArchivalSubmissionRetryService: queue scan failed', ['error' => $e->getMessage()]);
            $counts['errors']++;
            return $counts;
        }

        $epoch = $now ?? time();

        foreach ((array) $rows as $row) {
            if (is_array($row) === false) {
                continue;
            }
            $counts['scanned']++;

            $attempt     = max(1, (int) ($row['attemptNumber'] ?? 1));
            $sipBundelId = (string) ($row['sipBundelId'] ?? '');
            $caseId      = (string) ($row['zaakId'] ?? '');
            $lastTs      = (string) ($row['timestamp'] ?? '');

            if ($attempt >= self::ESCALATION_THRESHOLD) {
                $this->escalate($row);
                $counts['escalated']++;
                continue;
            }

            $waitSeconds = self::BACKOFF_SECONDS[$attempt] ?? 28800;
            $lastEpoch   = $this->parseEpoch($lastTs);
            if ($lastEpoch !== null && ($epoch - $lastEpoch) < $waitSeconds) {
                $counts['skipped_backoff']++;
                continue;
            }

            try {
                $result = $this->triggerService->submitToEdepot(
                    $sipBundelId,
                    $caseId,
                    ['retryCount' => $attempt, 'previousTransactieId' => (string) ($row['id'] ?? '')]
                );
                $newRow = [
                    'sipBundelId'           => $sipBundelId,
                    'zaakId'                => $caseId,
                    'attemptNumber'         => ($attempt + 1),
                    'status'                => ($result !== null && $result->submissionStatus !== 'FAILED') ? 'pending' : 'failed',
                    'timestamp'             => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
                    'previousTransactieId'  => (string) ($row['id'] ?? ''),
                    'archiefId'             => $result !== null ? $result->archiefId : '',
                    'submissionStatus'      => $result !== null ? $result->submissionStatus : 'FAILED',
                ];
                $objectService->saveObject($register, $schema, $newRow);
                $counts['retried']++;
                $this->triggerService->logEvent(
                    null,
                    $caseId !== '' ? $caseId : null,
                    'submission-retry',
                    'attempt='.($attempt + 1).' sipBundelId='.$sipBundelId.' status='.$newRow['submissionStatus']
                );
            } catch (\Throwable $e) {
                $counts['errors']++;
                $this->logger->warning(
                    'ArchivalSubmissionRetryService: retry failed',
                    ['error' => $e->getMessage(), 'sipBundelId' => $sipBundelId]
                );
            }
        }

        return $counts;
    }//end processRetryQueue()

    /**
     * Escalate a stuck transaction to the audit log + DIV channel.
     *
     * @param array<string, mixed> $row Failed transaction row.
     *
     * @return void
     */
    private function escalate(array $row): void
    {
        $sipBundelId = (string) ($row['sipBundelId'] ?? '');
        $caseId      = (string) ($row['zaakId'] ?? '');
        $attempt     = (int) ($row['attemptNumber'] ?? 0);
        $this->triggerService->logEvent(
            null,
            $caseId !== '' ? $caseId : null,
            'submission-escalated',
            'attempts='.$attempt.' sipBundelId='.$sipBundelId.' — manual DIV intervention required'
        );
        $this->logger->error(
            'ArchivalSubmissionRetryService: escalated after '.$attempt.' attempts',
            ['sipBundelId' => $sipBundelId, 'zaakId' => $caseId]
        );
    }//end escalate()

    /**
     * Parse an ISO-8601 timestamp into a UNIX epoch, null on failure.
     *
     * @param string $timestamp ISO-8601 string.
     *
     * @return int|null
     */
    private function parseEpoch(string $timestamp): ?int
    {
        if ($timestamp === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($timestamp))->getTimestamp();
        } catch (\Throwable $e) {
            return null;
        }
    }//end parseEpoch()
}//end class
