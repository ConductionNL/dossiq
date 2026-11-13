<?php

/**
 * Contract of EmailArchivalService
 *
 * The public surface of EmailArchivalService that other components call, so they depend
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
 * Contract of EmailArchivalService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface EmailArchivalServiceInterface {

	/**
	 * Find all archival records still in `failed` state.
	 *
	 * Used by `EmailPdfRetryJob` to retry archival; limit cap prevents the
	 * job from re-attempting an unbounded number of items in one pass.
	 *
	 * @param int $limit Hard upper bound on returned rows.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T09
	 */
	public function listFailedArchivals(int $limit = 50): array;

	/**
	 * Mark an archival attempt as failed and increment retry counter.
	 *
	 * @param string $archivalId Archival identifier.
	 * @param string $errorMessage Error context for the operator.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/archive/2026-06-13-case-email-integration/tasks.md#T05
	 */
	public function markFailed(string $archivalId, string $errorMessage): bool;
}//end interface
