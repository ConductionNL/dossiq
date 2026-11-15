<?php

/**
 * Contract of TenantQuotaService
 *
 * The public surface of TenantQuotaService that other components call, so they depend
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
 * Contract of TenantQuotaService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TenantQuotaServiceInterface {

	/**
	 * Read one tenantQuota row as an array, whatever shape it arrives in.
	 *
	 * Public because `ResetMonthlyQuotasJob` reads the same rows through
	 * `findAll()` and hands each one to `resetIfDue()`, which takes an array.
	 *
	 * @param mixed $row One findAll() row: an `ObjectEntity` or an array.
	 *
	 * @return array<string,mixed>|null The row, or null when it carries nothing readable.
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-real-time-quota-enforcement-req-005-b-req-005-c
	 */
	public function quotaRowAsArray(mixed $row): ?array;

	/**
	 * Reset due quotas. Used by the monthly background job.
	 *
	 * @param array<string,mixed> $quota Quota row.
	 *
	 * @return array<string,mixed> Updated row.
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-tier-based-quota-initialisation-req-005-a-req-005-e
	 */
	public function resetIfDue(array $quota): array;
}//end interface
