<?php

/**
 * Contract of QuotaGate
 *
 * The public surface of QuotaGate that other components call, so they depend
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
 * Contract of QuotaGate.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface QuotaGateInterface {

	/**
	 * The quota a schema's create consumes, or null when it consumes none.
	 *
	 * @param string $schemaSlug The schema slug of the created object.
	 *
	 * @return string|null The quota type.
	 *
	 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-a-tenant-past-its-hard-quota-cannot-create-more-req-tq-enf-01
	 */
	public function quotaTypeFor(string $schemaSlug): ?string;

	/**
	 * Count a saved write and notify the administrators when it crosses the soft limit.
	 *
	 * The notice goes out on the one write that moves usage from below the
	 * soft limit to at or above it, so it is sent once per period: usage
	 * only falls back below the soft limit when the period resets.
	 *
	 * @param string $quotaType The quota the write consumed.
	 * @param int    $amount    How much it consumed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-a-tenant-past-its-hard-quota-cannot-create-more-req-tq-enf-01
	 */
	public function record(string $quotaType, int $amount = 1): void;

	/**
	 * The refusal for a write that would pass a blocking limit, or null to let it through.
	 *
	 * A request without a tenant (a single-organisation install, a background
	 * job) is not counted. A quota enforced as `warn` lets the write through.
	 *
	 * @param string $quotaType The quota the write consumes.
	 * @param int    $amount    How much it consumes.
	 *
	 * @return array{message:string, quotaType:string, limit:int, tier:string}|null
	 *
	 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-a-tenant-past-its-hard-quota-cannot-create-more-req-tq-enf-01
	 */
	public function refusal(string $quotaType, int $amount = 1): ?array;
}//end interface
