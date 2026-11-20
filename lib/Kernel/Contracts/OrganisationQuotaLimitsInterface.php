<?php

/**
 * Contract of OrganisationQuotaLimits
 *
 * The public surface of OrganisationQuotaLimits that other components call, so they depend
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
 * Contract of OrganisationQuotaLimits.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface OrganisationQuotaLimitsInterface {

	/**
	 * Read the limit the Organisation carries, in the quota's own units.
	 *
	 * Null means unlimited, and an unresolvable Organisation gives null on
	 * purpose. That is the same answer this path gave before the limits moved:
	 * refusing traffic because a lookup failed would take a tenant offline
	 * over an unavailable service, and OpenRegister's own TenantQuotaMiddleware
	 * is what enforces requestQuota.
	 *
	 * @param string $tenantId  Tenant uuid, which is the Organisation uuid.
	 * @param string $quotaType The quota dimension.
	 *
	 * @return integer|null The limit, or null for unlimited.
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-tier-based-quota-initialisation-req-005-a-req-005-e
	 */
	public function read(string $tenantId, string $quotaType): ?int;

	/**
	 * Write a limit onto the Organisation.
	 *
	 * @param string       $tenantId  Tenant uuid, which is the Organisation uuid.
	 * @param string       $quotaType The quota dimension.
	 * @param integer|null $limit     The limit in the quota's own units, or null for unlimited.
	 *
	 * @return boolean Whether the limit was written.
	 *
	 * @spec openspec/specs/tenant-quotas/spec.md#requirement-tier-based-quota-initialisation-req-005-a-req-005-e
	 */
	public function write(string $tenantId, string $quotaType, ?int $limit): bool;
}//end interface
