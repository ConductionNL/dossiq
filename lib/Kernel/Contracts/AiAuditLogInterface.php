<?php

/**
 * Contract of AiAuditLog
 *
 * The public surface of AiAuditLog that other components call, so they depend
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
 * Contract of AiAuditLog.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AiAuditLogInterface {

	/**
	 * List recorded AI audit entries from OpenRegister, newest first.
	 *
	 * Degrades gracefully (empty result, warning logged, no throw) when AI audit
	 * storage is not configured or the OpenRegister lookup fails, so a
	 * misconfigured instance never 500s the oversight surface.
	 *
	 * @param array<string, mixed> $filters Optional filters: 'caseId', 'type'.
	 * @param int $limit Page size (clamped to 1-200, default 50).
	 * @param int $offset Paging offset (clamped to >= 0).
	 *
	 * @return array{entries: array<int, array<string, mixed>>, total: int|null, limit: int, offset: int}
	 *
	 * @spec openspec/changes/archive/2026-07-12-ai-oversight-log/tasks.md#1.1
	 */
	public function list(array $filters = [], int $limit = self::DEFAULT_LIMIT, int $offset = 0): array;
}//end interface
