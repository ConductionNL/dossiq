<?php

/**
 * Procest ArchivalTriggerService.
 *
 * Daemon that walks closed cases and either:
 *   - creates/updates an OverdrachtTrigger with status=gereed-voor-overdracht
 *     when a BewaarTermijnRegel matches the case's zaaktypeKey,
 *   - blocks (status=geblokkeerd-geen-regel) when no rule matches,
 *   - suspends (status=opgeschort-juridische-procedure) when an active
 *     bezwaar/beroep is registered on the case.
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
 * @spec openspec/changes/archief-edepot-handover-02-retention-trigger/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Retention-rule trigger detection.
 */
class ArchivalTriggerService
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Scan a list of closed cases and produce/update OverdrachtTrigger rows.
     *
     * @param array<int, array<string, mixed>> $closedCases List of closed-case rows.
     *
     * @return array<string, int>
     *
     * @spec openspec/changes/archief-edepot-handover-02-retention-trigger/tasks.md
     */
    public function detectReadyCases(array $closedCases): array
    {
        $counts = ['ready' => 0, 'blocked' => 0, 'suspended' => 0, 'errors' => 0];
        foreach ($closedCases as $case) {
            try {
                $this->processCase((array) $case, $counts);
            } catch (\Throwable $e) {
                $counts['errors']++;
                $this->logger->warning('Archival trigger row failed', ['error' => $e->getMessage()]);
            }
        }
        return $counts;
    }//end detectReadyCases()

    /**
     * Update a trigger's status.
     *
     * @param string $triggerId Trigger id.
     * @param string $newStatus New status.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/archief-edepot-handover-02-retention-trigger/tasks.md
     */
    public function updateTriggerStatus(string $triggerId, string $newStatus): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('overdracht_trigger_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return null;
        }

        try {
            $row = $objectService->find($triggerId, register: $register, schema: $schema);
        } catch (\Throwable $e) {
            return null;
        }
        if (is_array($row) === false) {
            return null;
        }

        $row['status'] = $newStatus;
        try {
            $saved = $objectService->saveObject($register, $schema, $row);
            return is_array($saved) === true ? $saved : $row;
        } catch (\Throwable $e) {
            return $row;
        }
    }//end updateTriggerStatus()

    /**
     * Append an audit event.
     *
     * @param string|null $triggerId Trigger id.
     * @param string|null $zaakId    Case id.
     * @param string      $eventType Event type.
     * @param string      $details   Details.
     * @param string      $actor     Actor.
     *
     * @return array<string, mixed>|null
     *
     * @spec openspec/changes/archief-edepot-handover-02-retention-trigger/tasks.md
     */
    public function logEvent(?string $triggerId, ?string $zaakId, string $eventType, string $details = '', string $actor = 'system'): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('overdracht_audit_log_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return null;
        }

        $row = [
            'triggerId' => $triggerId,
            'zaakId'    => $zaakId,
            'eventType' => $eventType,
            'timestamp' => (new DateTimeImmutable())->format('Y-m-d\TH:i:sP'),
            'actor'     => $actor,
            'details'   => $details,
        ];

        try {
            $saved = $objectService->saveObject($register, $schema, $row);
            return is_array($saved) === true ? $saved : $row;
        } catch (\Throwable $e) {
            return $row;
        }
    }//end logEvent()

    /**
     * Process one case row.
     *
     * @param array<string, mixed> $case   Case.
     * @param array<string, int>   $counts Counts (by ref).
     *
     * @return void
     */
    private function processCase(array $case, array &$counts): void
    {
        $caseId      = (string) ($case['id'] ?? '');
        $zaaktypeKey = (string) ($case['caseType'] ?? ($case['zaaktype'] ?? ''));
        $closedAt    = (string) ($case['closedAt'] ?? '');
        $hasBezwaar  = (bool)   ($case['hasActiveBezwaar'] ?? false);
        if ($caseId === '' || $zaaktypeKey === '') {
            return;
        }

        $rule = $this->findRule($zaaktypeKey);
        if ($rule === null) {
            $this->upsertTrigger($caseId, $zaaktypeKey, [
                'afsluitingsDatum' => $closedAt,
                'status'           => 'geblokkeerd-geen-regel',
                'redenBlokkering'  => 'Geen BewaarTermijnRegel voor zaaktype "'.$zaaktypeKey.'"',
            ]);
            $counts['blocked']++;
            $this->logEvent(null, $caseId, 'trigger-detected', 'blocked: no rule for zaaktype "'.$zaaktypeKey.'"');
            return;
        }

        if ($hasBezwaar === true) {
            $this->upsertTrigger($caseId, $zaaktypeKey, [
                'afsluitingsDatum'  => $closedAt,
                'bewaartermijnJaren' => (int) ($rule['bewaartermijnJaren'] ?? 0),
                'status'            => 'opgeschort-juridische-procedure',
                'redenBlokkering'   => 'Actieve bezwaar/beroep procedure',
            ]);
            $counts['suspended']++;
            $this->logEvent(null, $caseId, 'trigger-detected', 'suspended: active bezwaar');
            return;
        }

        $bewaarJaren = (int) ($rule['bewaartermijnJaren'] ?? 0);
        $overdracht  = $this->computeOverdrachtDatum($closedAt, $bewaarJaren);
        $this->upsertTrigger($caseId, $zaaktypeKey, [
            'afsluitingsDatum'   => $closedAt,
            'bewaartermijnJaren' => $bewaarJaren,
            'overdrachtDatum'    => $overdracht,
            'status'             => 'gereed-voor-overdracht',
            'redenBlokkering'    => '',
        ]);
        $counts['ready']++;
        $this->logEvent(null, $caseId, 'trigger-detected', 'ready: overdrachtDatum '.$overdracht);
    }

    /**
     * Find the active rule for a zaaktype.
     *
     * @param string $zaaktypeKey Zaaktype key.
     * @return array<string, mixed>|null
     */
    private function findRule(string $zaaktypeKey): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('bewaar_termijn_regel_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return null;
        }
        try {
            $rows = $objectService->findObjects($register, $schema, ['zaaktypeKey' => $zaaktypeKey]);
        } catch (\Throwable $e) {
            return null;
        }
        foreach ((array) $rows as $row) {
            if (is_array($row) === true && (bool) ($row['isActive'] ?? false) === true) {
                return $row;
            }
        }
        return null;
    }

    /**
     * Upsert a trigger for a case (one trigger per zaakId).
     *
     * @param string               $caseId      Case id.
     * @param string               $zaaktypeKey Zaaktype key.
     * @param array<string, mixed> $fields      Fields.
     * @return array<string, mixed>|null
     */
    private function upsertTrigger(string $caseId, string $zaaktypeKey, array $fields): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        $register      = (string) $this->settingsService->getConfigValue('register');
        $schema        = (string) $this->settingsService->getConfigValue('overdracht_trigger_schema');
        if ($objectService === null || $register === '' || $schema === '') {
            return null;
        }

        try {
            $existing = $objectService->findObjects($register, $schema, ['zaakId' => $caseId]);
        } catch (\Throwable $e) {
            $existing = [];
        }

        $row = is_array($existing) === true && count($existing) > 0 ? (array) $existing[0] : [];
        $row['zaakId']      = $caseId;
        $row['zaaktypeKey'] = $zaaktypeKey;
        $row['aanmeldingsDatum'] = $row['aanmeldingsDatum'] ?? (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');
        foreach ($fields as $k => $v) {
            $row[$k] = $v;
        }

        try {
            $saved = $objectService->saveObject($register, $schema, $row);
            return is_array($saved) === true ? $saved : $row;
        } catch (\Throwable $e) {
            return $row;
        }
    }

    /**
     * @param string $closedAt    YYYY-MM-DD.
     * @param int    $bewaarJaren Years (9999 = permanent).
     * @return string
     */
    private function computeOverdrachtDatum(string $closedAt, int $bewaarJaren): string
    {
        if ($closedAt === '' || $bewaarJaren <= 0 || $bewaarJaren >= 9999) {
            return '';
        }
        try {
            return (new DateTimeImmutable($closedAt))->modify('+'.$bewaarJaren.' years')->format('Y-m-d');
        } catch (\Throwable $e) {
            return '';
        }
    }
}//end class
