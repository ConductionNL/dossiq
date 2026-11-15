<?php

/**
 * Contract of DeadlineEscalationService
 *
 * The public surface of DeadlineEscalationService that other components call, so they depend
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
 * Contract of DeadlineEscalationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface DeadlineEscalationServiceInterface {

	/**
	 * Notify a threshold for a TermijnInstance (idempotent on duplicates).
	 *
	 * @param array<string, mixed> $instance TermijnInstance row.
	 * @param int $threshold Threshold bucket (14/7/2/0).
	 *
	 * @return bool True if a notification was sent (i.e. not a duplicate).
	 *
	 * @spec openspec/changes/archive/2026-06-13-termijnbewaking-dwangsom-engine-04-daily-scan-escalation/tasks.md
	 * @spec openspec/specs/case-priority/spec.md
	 */
	public function notifyThreshold(array $instance, int $threshold): bool;
}//end interface
