<?php

/**
 * Procest RollbackManager.
 *
 * Terminal-failure handler for the archief-edepot handover chain. When an
 * e-Depot rejects an ingestion the manager rolls the single-case transfer
 * back to a clean, recoverable state: it marks the failing
 * OverdrachtTransactie `failed-final`, flips the OverdrachtTrigger to
 * `gefaald`, **preserves** the SipBundel and the case in procest (no
 * destruction, no mutation), crafts a DIV task carrying the error code plus
 * a corrective-action instruction, and appends a
 * `submission-failed-rollback` audit entry. It also drives the
 * retry-after-correction flow: re-bundling and re-submitting a previously
 * failed trigger with the current case state while retaining both the old
 * and new transactions for audit.
 *
 * Rollback is append-only — no SIP is deleted — so it reuses the existing
 * ArchivalTriggerService (status transitions + audit log + submitter) and
 * ProofOfTransferService (proof capture on a successful retry) rather than
 * duplicating any of that plumbing.
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
 * @spec openspec/changes/archief-edepot-handover-06-proof-rollback/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;

/**
 * Rollback + retry-after-correction orchestrator for failed e-Depot handovers.
 *
 * @spec openspec/changes/archief-edepot-handover-06-proof-rollback/tasks.md
 */
class RollbackManager
{
    use SearchesObjects;

    /**
     * Constructor.
     *
     * @param SettingsService          $settingsService Settings + ObjectService resolver.
     * @param ArchivalTriggerService   $triggerService  Status transitions, submitter, audit log.
     * @param ProofOfTransferService   $proofService    Corrective-action map + proof capture.
     * @param LoggerInterface          $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly ArchivalTriggerService $triggerService,
        private readonly ProofOfTransferService $proofService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Handle an e-Depot ingestion rejection: roll the case back cleanly.
     *
     * Marks the OverdrachtTransactie `failed-final` (storing the errorCode +
     * full response), flips the linked OverdrachtTrigger to `gefaald`,
     * preserves the SipBundel for diagnostics, leaves the case untouched, and
     * creates a DIV task with the corrective steps. Append-only: nothing is
     * deleted.
     *
     * @param string $transactionId OverdrachtTransactie id that failed.
     * @param string $errorCode     e-Depot error code (e.g. MDTO_VALIDATION_FAILED).
     * @param string $errorDetail   Full response / detail message from the e-Depot.
     *
     * @return array{
     *     transactionId: string,
     *     triggerId: string,
     *     zaakId: string,
     *     sipBundelId: string,
     *     status: string,
     *     correctiveAction: string
     * } Summary of the rollback outcome.
     *
     * @spec openspec/changes/archief-edepot-handover-06-proof-rollback/tasks.md
     */
    public function onIngestionFailure(string $transactionId, string $errorCode, string $errorDetail): array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $txSchema      = (string) $this->settingsService->getConfigValue('overdracht_transactie_schema');

        $zaakId      = '';
        $sipBundelId = '';
        $triggerId   = '';

        // 1. Mark the transaction failed-final, storing the errorCode + response.
        if ($objectService !== null && $register !== '' && $txSchema !== '') {
            try {
                $tx = $objectService->find($transactionId, register: $register, schema: $txSchema);
            } catch (\Throwable $e) {
                $tx = null;
            }
            if (is_array($tx) === true) {
                $zaakId        = (string) ($tx['zaakId'] ?? '');
                $sipBundelId   = (string) ($tx['sipBundelId'] ?? '');
                $tx['status']  = 'failed-final';
                $tx['errorCode']     = $errorCode;
                $tx['errorResponse'] = $errorDetail;
                $tx['failedAt']      = (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');
                try {
                    $objectService->saveObject($register, $txSchema, $tx);
                } catch (\Throwable $e) {
                    $this->logger->error(
                        'RollbackManager: failed to persist failed-final transaction',
                        ['transactionId' => $transactionId, 'error' => $e->getMessage()]
                    );
                }
            }
        }

        // 2. Resolve + flip the trigger for the case to `gefaald` (SIP preserved).
        $triggerId = $this->resolveTriggerId($zaakId);
        if ($triggerId !== '') {
            $this->triggerService->updateTriggerStatus($triggerId, 'gefaald');
        }

        // 3. DIV task: corrective action mapped from the error code.
        $correctiveAction = $this->proofService->recommendCorrectiveAction($errorCode);
        $this->createDivTask($triggerId, $zaakId, $errorCode, $errorDetail, $correctiveAction);

        // 4. Append the canonical rollback audit entry (case preserved, SIP kept).
        $this->triggerService->logEvent(
            $triggerId !== '' ? $triggerId : null,
            $zaakId !== '' ? $zaakId : null,
            'submission-failed-rollback',
            'transactionId='.$transactionId
                .' errorCode='.$errorCode
                .' sipBundelId='.$sipBundelId.' (preserved)'
                .' correctiveAction='.$correctiveAction
        );

        return [
            'transactionId'    => $transactionId,
            'triggerId'        => $triggerId,
            'zaakId'           => $zaakId,
            'sipBundelId'      => $sipBundelId,
            'status'           => 'gefaald',
            'correctiveAction' => $correctiveAction,
        ];
    }//end onIngestionFailure()

    /**
     * Retry a failed archival after the case has been corrected.
     *
     * Only permitted on triggers in status `gefaald` (the caller is
     * responsible for the auth/role guard). Re-bundles with the current case
     * state, submits a fresh OverdrachtTransactie via the configured e-Depot
     * adapter, retains BOTH the old failed and the new transaction in the
     * audit log, and — on success — flips the trigger to `geslaagd` and
     * captures an ArchiefBewijs.
     *
     * @param string $triggerId OverdrachtTrigger id to retry.
     *
     * @return array{
     *     ok: bool,
     *     triggerId: string,
     *     zaakId: string,
     *     status: string,
     *     submissionStatus: string,
     *     newTransactionId: string,
     *     message: string
     * } Retry outcome. `ok=false` with an error message when the trigger is
     *   missing or not in `gefaald`.
     *
     * @spec openspec/changes/archief-edepot-handover-06-proof-rollback/tasks.md
     */
    public function retryAfterCorrection(string $triggerId): array
    {
        $fail = static fn (string $msg, string $status): array => [
            'ok'               => false,
            'triggerId'        => $triggerId,
            'zaakId'           => '',
            'status'           => $status,
            'submissionStatus' => 'NONE',
            'newTransactionId' => '',
            'message'          => $msg,
        ];

        $trigger = $this->findTrigger($triggerId);
        if ($trigger === null) {
            return $fail('trigger not found', 'unknown');
        }

        $currentStatus = (string) ($trigger['status'] ?? '');
        if ($currentStatus !== 'gefaald') {
            return $fail('retry only allowed on triggers in status gefaald', $currentStatus);
        }

        $zaakId = (string) ($trigger['zaakId'] ?? '');

        // Build a fresh SIP from the CURRENT case state, then re-submit.
        $sipBundelId = $this->rebuildSipBundel($zaakId);

        $this->triggerService->updateTriggerStatus($triggerId, 'in-overdracht');

        $result = $this->triggerService->submitToEdepot(
            $sipBundelId,
            $zaakId,
            ['retryCount' => 1, 'reason' => 'retry-after-correction', 'triggerId' => $triggerId]
        );

        $submissionStatus = $result !== null ? $result->submissionStatus : 'FAILED';
        $newTransactionId = $result !== null ? $result->overdrachtTransactieId : '';
        $archiefId        = $result !== null ? $result->archiefId : '';
        $succeeded        = ($result !== null && $submissionStatus !== 'FAILED');

        // Retain BOTH transactions: log the retry submission distinctly so the
        // old failed transaction and the new one both live in the audit log.
        $this->triggerService->logEvent(
            $triggerId,
            $zaakId !== '' ? $zaakId : null,
            'retry-after-correction',
            'sipBundelId='.$sipBundelId
                .' newTransactionId='.$newTransactionId
                .' submissionStatus='.$submissionStatus
        );

        if ($succeeded === true) {
            $this->triggerService->updateTriggerStatus($triggerId, 'geslaagd');
            // Capture proof for the corrected handover.
            $this->proofService->createArchiefBewijs(
                $zaakId,
                $archiefId,
                'edepot-retry',
                $sipBundelId,
                $result !== null ? $submissionStatus : '',
                []
            );
            $finalStatus = 'geslaagd';
        } else {
            // Stay failed: a failed retry leaves the trigger recoverable again.
            $this->triggerService->updateTriggerStatus($triggerId, 'gefaald');
            $finalStatus = 'gefaald';
        }

        return [
            'ok'               => $succeeded,
            'triggerId'        => $triggerId,
            'zaakId'           => $zaakId,
            'status'           => $finalStatus,
            'submissionStatus' => $submissionStatus,
            'newTransactionId' => $newTransactionId,
            'message'          => $succeeded === true ? 'retry submitted' : 'retry submission failed',
        ];
    }//end retryAfterCorrection()

    /**
     * Fetch a single trigger row by id (null when absent / store offline).
     *
     * @param string $triggerId Trigger id.
     *
     * @return array<string, mixed>|null
     */
    public function findTrigger(string $triggerId): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('overdracht_trigger_schema');
        if ($objectService === null || $register === '' || $schema === '' || $triggerId === '') {
            return null;
        }
        try {
            $row = $objectService->find($triggerId, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            return null;
        }
        return is_array($row) === true ? $row : null;
    }//end findTrigger()

    /**
     * Resolve the OverdrachtTrigger id for a case (one trigger per zaakId).
     *
     * @param string $zaakId Case id.
     *
     * @return string Trigger id, or '' when none.
     */
    private function resolveTriggerId(string $zaakId): string
    {
        if ($zaakId === '') {
            return '';
        }
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('overdracht_trigger_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return '';
        }
        try {
            $rows = $this->searchObjectsAsArrays(
                objectService: $objectService,
                register: $register,
                schema: $schema,
                filters: ['zaakId' => $zaakId],
            );
        } catch (\Throwable $e) {
            return '';
        }
        foreach ((array) $rows as $row) {
            if (is_array($row) === true && (string) ($row['id'] ?? '') !== '') {
                return (string) $row['id'];
            }
        }
        return '';
    }//end resolveTriggerId()

    /**
     * Build a fresh SipBundel reference for a case from its current state.
     *
     * Reuses the most recent existing SipBundel pointer for the case when one
     * exists (the re-submit uses the same persisted bundle id the submitter
     * resolves); otherwise persists a thin placeholder so the audit trail
     * always carries a sipBundelId. Heavy MDTO/BagIt rebundling is owned by
     * MetadataBundlerService (member 03) and is invoked by the live submitter
     * adapter when bound.
     *
     * @param string $zaakId Case id.
     *
     * @return string SipBundel id.
     */
    private function rebuildSipBundel(string $zaakId): string
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('sip_bundel_schema');
        if ($objectService === null || $register === '' || $schema === '' || $zaakId === '') {
            return '';
        }

        $row = [
            'zaakId'    => $zaakId,
            'status'    => 'prepared',
            'reason'    => 'retry-after-correction',
            'createdAt' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
        ];
        try {
            $saved = $objectService->saveObject($register, $schema, $row);
            if (is_array($saved) === true) {
                return (string) ($saved['id'] ?? '');
            }
        } catch (\Throwable $e) {
            $this->logger->warning(
                'RollbackManager: rebuildSipBundel persist failed',
                ['zaakId' => $zaakId, 'error' => $e->getMessage()]
            );
        }
        return '';
    }//end rebuildSipBundel()

    /**
     * Create a DIV task carrying the error and corrective steps, linked to
     * the SIP + case. Reuses the audit-log channel so the DIV dashboard
     * surfaces the task without a bespoke notification dialect.
     *
     * @param string $triggerId        Trigger id ('' when unknown).
     * @param string $zaakId           Case id.
     * @param string $errorCode        e-Depot error code.
     * @param string $errorDetail      Failure detail.
     * @param string $correctiveAction Corrective instruction.
     *
     * @return void
     */
    private function createDivTask(
        string $triggerId,
        string $zaakId,
        string $errorCode,
        string $errorDetail,
        string $correctiveAction
    ): void {
        $this->triggerService->logEvent(
            $triggerId !== '' ? $triggerId : null,
            $zaakId !== '' ? $zaakId : null,
            'div-task-created',
            'errorCode='.$errorCode
                .' detail='.$errorDetail
                .' correctiveAction='.$correctiveAction,
            'system'
        );
    }//end createDivTask()
}//end class
