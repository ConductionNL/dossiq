<?php

/**
 * Contract of TenantAuditTrailService
 *
 * The public surface of TenantAuditTrailService that other components call, so they depend
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
 * Contract of TenantAuditTrailService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TenantAuditTrailServiceInterface {

	/**
	 * Emit an audit-trail entry: write one hash-chained OpenRegister audit row
	 * anchored to the tenant ObjectEntity, and mirror it to the log for SIEM
	 * ingestion. Returns the normalised entry, including a `persisted` flag
	 * reporting whether the durable row actually landed.
	 *
	 * Audit-write failures are swallowed — a failed audit MUST NOT break the
	 * mutation the caller is performing — but they are reported truthfully via
	 * `persisted:false` and an error log, and they turn the hardening
	 * checklist's `audit_logged_mutations` claim to `unverified` (fail-closed).
	 *
	 * Payload keys: action (string), actor (string), role (?string),
	 * resource (?string), tenantId (string), ip (?string), ua (?string),
	 * bio (?array<string, mixed>).
	 *
	 * @param array<string, mixed> $payload Audit payload.
	 *
	 * @return array<string,mixed> Normalised entry (with `persisted`).
	 *
	 * @spec openspec/specs/tenant-compliance/spec.md
	 */
	public function emit(array $payload): array;
}//end interface
