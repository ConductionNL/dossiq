<?php

/**
 * Contract of AiAuditService
 *
 * The public surface of AiAuditService that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of AiAuditService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AiAuditServiceInterface {

	/**
	 * List recorded AI audit entries from OpenRegister, newest first.
	 *
	 * Reads the same audit sink {@see AiAuditLog::record()} writes to. Degrades
	 * gracefully (empty result, warning logged, no throw) when AI audit storage
	 * is not configured or the OpenRegister lookup fails, so a misconfigured
	 * instance never 500s the oversight surface.
	 *
	 * @param array<string, mixed> $filters Optional filters: 'caseId', 'type'.
	 * @param int $limit Page size (clamped to 1-200, default 50).
	 * @param int $offset Paging offset (clamped to >= 0).
	 *
	 * @return array{entries: array<int, array<string, mixed>>, total: int|null, limit: int, offset: int}
	 *
	 * @spec openspec/changes/archive/2026-07-12-ai-oversight-log/tasks.md#1.1
	 */
	public function listAuditEntries(array $filters = [], int $limit = 50, int $offset = 0): array;

	/**
	 * Record a user action on an AI suggestion (accept, reject, modify, ignore).
	 *
	 * @param string $caseId The case ID
	 * @param string $type AI type (classification, extraction, etc.)
	 * @param string $userAction User action (accepted, rejected, modified, ignored)
	 * @param array $suggestion The original suggestion
	 * @param array|null $actualValue The value actually applied
	 * @param string|null $reason Reason for rejection/modification
	 * @param string $userId The current user ID
	 * @param array<string, string> $context Optional `unusedBecause` (with ignored), `prompt`, `model`,
	 *                                       `suggestionId` and `documentId`.
	 *
	 * @return array
	 *
	 * @spec openspec/changes/archive/2026-05-31-retrofit-2026-05-24-case-management/tasks.md
	 */
	public function recordUserAction(
		string $caseId,
		string $type,
		string $userAction,
		array $suggestion,
		?array $actualValue,
		?string $reason,
		string $userId,
		array $context = [],
	): array;
}//end interface
