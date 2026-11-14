<?php

/**
 * Contract of TenantBillingService
 *
 * The public surface of TenantBillingService that other components call, so they depend
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

use InvalidArgumentException;

/**
 * Contract of TenantBillingService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface TenantBillingServiceInterface {

	/**
	 * Fetch all events for a given month for a tenant.
	 *
	 * `findAll()` returns `ObjectEntity` objects. The month filter below used
	 * to index them as arrays, outside any catch, so every billing figure and
	 * every invoice run for a tenant with events died on the first row. Each
	 * row is read as an array first, and a row that carries nothing readable
	 * has no `occurredAt` and so falls outside every month.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $month YYYY-MM.
	 *
	 * @return array<int, array<string,mixed>>
	 *
	 * @spec openspec/specs/tenant-billing/spec.md#requirement-daily-billing-export-to-shillinq-req-007-b
	 */
	public function fetchEventsForMonth(string $tenantId, string $month): array;

	/**
	 * Aggregate billing for a month.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $month YYYY-MM.
	 *
	 * @return array{eventCount:int, totalAmount:float, byType:array<string, array{count:float, amount:float}>, unpricedCount:int}
	 *
	 * @throws InvalidArgumentException When month is malformed.
	 *
	 * @spec openspec/specs/tenant-billing/spec.md#requirement-billing-event-emission-on-case-lifecycle-req-007-a
	 */
	public function getMonthBilling(string $tenantId, string $month): array;

	/**
	 * Run the end-to-end monthly invoicing for one tenant: collect the month's
	 * unbilled usage events, compute the amount, export a Shillinq invoice, and
	 * stamp the events with the returned invoice reference.
	 *
	 * This is the orchestration the billing pipeline lacked: emitEvent /
	 * aggregate / groupForInvoicing / buildInvoicePayload / exportInvoice /
	 * markExported all existed but nothing chained them, so every tenant
	 * invoice was EUR0 and exportInvoice had zero callers (procest#223
	 * finding 2 — orphaned billing capability).
	 *
	 * Running a month twice hands one invoice to the finance app: the first
	 * run stamps every event it bills, and a stamped event is never billed
	 * again.
	 *
	 * @param string $tenantId Tenant UUID.
	 * @param string $month YYYY-MM, or `previous` for the month before this one.
	 *
	 * @return array{tenantId:string, month:string, eventCount:int, amount:float, currency:string, exported:bool, invoiceRef:?string, error:?string}
	 *
	 * @throws InvalidArgumentException When month is malformed.
	 *
	 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-an-administrator-sees-and-runs-a-tenants-monthly-billing-req-tq-bill-01
	 */
	public function runInvoicing(string $tenantId, string $month): array;
}//end interface
