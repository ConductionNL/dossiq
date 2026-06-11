/**
 * Leverancier (Supplier) Portal API service for Procest.
 *
 * Endpoints under /apps/procest/api/leverancier-portaal/* — operator-side
 * reads that surface the leverancier-zaakportaal Vue components against the
 * existing supplier services. Every request requires a `supplierRef` (the
 * supplier UUID) — this is the scoping primitive that the backend
 * `SupplierScopeService::listSupplierObjects()` already enforces; passing
 * a foreign `supplierRef` cannot leak cross-supplier data because the
 * service filters by it on every read.
 *
 * Uses @nextcloud/axios so CSRF tokens are attached automatically.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @spec openspec/changes/leverancier-zaakportaal-15-dashboard-shell/tasks.md
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Build a Leverancier-portaal API URL.
 *
 * @param {string} path Sub-path under /apps/procest/api/leverancier-portaal
 * @return {string} Fully qualified Nextcloud URL
 */
function url(path) {
	return generateUrl(`/apps/procest/api/leverancier-portaal/${path}`)
}

/**
 * Fetch the dashboard summary (4 cards).
 *
 * @param {string} supplierRef Supplier UUID
 * @return {Promise<object>} Card payload: { tenders, invoices, contracts, kpi }
 */
export async function getDashboardSummary(supplierRef) {
	const r = await axios.get(url('dashboard'), { params: { supplierRef } })
	return r.data
}

/**
 * List tenders for the supplier.
 *
 * @param {string} supplierRef Supplier UUID
 * @param {string} [status] Optional status filter (submitted/evaluating/awarded/rejected/withdrawn)
 * @return {Promise<{ items: object[], total: number }>} Tender list.
 */
export async function listTenders(supplierRef, status) {
	const params = { supplierRef }
	if (status) {
		params.status = status
	}
	const r = await axios.get(url('tenders'), { params })
	return r.data
}

/**
 * Fetch a single tender by ID.
 *
 * @param {string} supplierRef Supplier UUID
 * @param {string} tenderId Tender UUID
 * @return {Promise<object|null>} Tender or null on 404.
 */
export async function getTender(supplierRef, tenderId) {
	try {
		const r = await axios.get(url(`tenders/${tenderId}`), { params: { supplierRef } })
		return r.data
	} catch (e) {
		if (e.response && e.response.status === 404) {
			return null
		}
		throw e
	}
}

/**
 * List invoices for the supplier.
 *
 * @param {string} supplierRef Supplier UUID
 * @return {Promise<{ items: object[], total: number }>} Invoice list.
 */
export async function listInvoices(supplierRef) {
	const r = await axios.get(url('invoices'), { params: { supplierRef } })
	return r.data
}

/**
 * List contracts for the supplier.
 *
 * @param {string} supplierRef Supplier UUID
 * @return {Promise<{ items: object[], total: number }>} Contract list.
 */
export async function listContracts(supplierRef) {
	const r = await axios.get(url('contracts'), { params: { supplierRef } })
	return r.data
}

/**
 * Get the KPI summary.
 *
 * @param {string} supplierRef Supplier UUID
 * @return {Promise<object>} Aggregated KPI map.
 */
export async function getKpi(supplierRef) {
	const r = await axios.get(url('kpi'), { params: { supplierRef } })
	return r.data
}

/**
 * Get the message thread for a case (entity-scoped).
 *
 * @param {string} supplierRef Supplier UUID
 * @param {string} caseRef Case UUID — the conversation key.
 * @return {Promise<{ items: object[], total: number }>} Message thread.
 */
export async function listMessages(supplierRef, caseRef) {
	const r = await axios.get(url('messages'), { params: { supplierRef, caseRef } })
	return r.data
}
