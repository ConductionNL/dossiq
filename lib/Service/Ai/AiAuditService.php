<?php

/**
 * Procest AI audit service.
 *
 * The oversight surface of the AI feature set: recording what a human did with
 * an AI suggestion (accept / reject / modify), recording a conversational
 * assistant exchange, and reading the Algoritmeregister trail back for the
 * oversight page and the CSV export.
 *
 * Split out of {@see \OCA\Procest\Service\AiService} so that model
 * orchestration (deciding whether a feature is on, building a prompt, making
 * the one outbound model call) and oversight (what was suggested, what a human
 * did with it, and who can read that back) are separate responsibilities.
 * Storage itself stays in {@see AiAuditLog}; this class is the surface the
 * controllers and assistant services consume.
 *
 * @category Service
 * @package  OCA\Procest\Service\Ai
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/ai-oversight-log/tasks.md#1.1
 * @spec openspec/specs/case-assistant-via-hermiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Ai;

/**
 * Records and reads the AI oversight audit trail.
 *
 * @spec openspec/changes/ai-oversight-log/tasks.md#1.1
 */
class AiAuditService
{
    /**
     * Constructor.
     *
     * @param AiAuditLog      $audit         The oversight audit trail storage.
     * @param AiModelIdentity $modelIdentity The configured model identifier.
     *
     * @return void
     */
    public function __construct(
        private AiAuditLog $audit,
        private AiModelIdentity $modelIdentity,
    ) {
    }//end __construct()

    /**
     * Record a user action on an AI suggestion (accept, reject, modify).
     *
     * @param string      $caseId      The case ID
     * @param string      $type        AI type (classification, extraction, etc.)
     * @param string      $userAction  User action (accepted, rejected, modified)
     * @param array       $suggestion  The original suggestion
     * @param array|null  $actualValue The value actually applied
     * @param string|null $reason      Reason for rejection/modification
     * @param string      $userId      The current user ID
     *
     * @return array
     *
     * @spec openspec/changes/retrofit-2026-05-24-case-management/tasks.md
     */
    public function recordUserAction(
        string $caseId,
        string $type,
        string $userAction,
        array $suggestion,
        ?array $actualValue,
        ?string $reason,
        string $userId,
    ): array {
        $this->recordAuditEntry(
                entry: [
                    'type'        => $type,
                    'action'      => $userAction,
                    'caseId'      => $caseId,
                    'model'       => $this->modelIdentity->identifier(),
                    'suggestion'  => $suggestion,
                    'userAction'  => $userAction,
                    'actualValue' => ($actualValue ?? []),
                    'reason'      => ($reason ?? ''),
                    'userId'      => $userId,
                    'timestamp'   => date('c'),
                ]
                );

        return ['success' => true];
    }//end recordUserAction()

    /**
     * List recorded AI audit entries from OpenRegister, newest first.
     *
     * Reads the same audit sink {@see AiAuditLog::record()} writes to. Degrades
     * gracefully (empty result, warning logged, no throw) when AI audit storage
     * is not configured or the OpenRegister lookup fails, so a misconfigured
     * instance never 500s the oversight surface.
     *
     * @param array<string, mixed> $filters Optional filters: 'caseId', 'type'.
     * @param int                  $limit   Page size (clamped to 1-200, default 50).
     * @param int                  $offset  Paging offset (clamped to >= 0).
     *
     * @return array{entries: array<int, array<string, mixed>>, total: int|null, limit: int, offset: int}
     *
     * @spec openspec/changes/ai-oversight-log/tasks.md#1.1
     */
    public function listAuditEntries(array $filters=[], int $limit=50, int $offset=0): array
    {
        return $this->audit->list(filters: $filters, limit: $limit, offset: $offset);
    }//end listAuditEntries()

    /**
     * Record a pre-built audit entry for the conversational case assistant.
     *
     * The case-assistant surface lives in `AssistantController` /
     * `HermiqAssistantClient` — a separate class per the fleet rule that AI
     * functionality / LLM calls live in Hermiq, not in `AiService`. Those
     * callers build their own entry and hand it here, so the existing
     * Algoritmeregister oversight trail
     * (`listAuditEntries()`/`AiAuditExportController`) covers the
     * conversational surface too, with no second audit mechanism. This method
     * carries no LLM logic; it only forwards an already-built entry to the
     * existing writer.
     *
     * @param array $entry The audit entry data — same shape as the other
     *                     `recordAuditEntry()` call sites (`type`, `action`,
     *                     `caseId`, `model`, `prompt`, `suggestion`,
     *                     `confidence`, `userId`, `timestamp`, `responseTimeMs`).
     *
     * @return void
     *
     * @spec openspec/specs/case-assistant-via-hermiq/spec.md
     */
    public function recordAssistantAuditEntry(array $entry): void
    {
        $this->recordAuditEntry(entry: $entry);
    }//end recordAssistantAuditEntry()

    /**
     * Record an AI audit trail entry in OpenRegister.
     *
     * @param array $entry The audit entry data
     *
     * @return void
     */
    private function recordAuditEntry(array $entry): void
    {
        $this->audit->record(entry: $entry);
    }//end recordAuditEntry()
}//end class
