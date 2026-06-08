/**
 * Leges API service for Procest.
 *
 * Per-case leges calculation, audit trail and refund live on the Procest
 * controller:
 *   GET    /apps/procest/api/cases/{caseId}/leges              — current calculation
 *   POST   /apps/procest/api/cases/{caseId}/leges/calculate    — (re)calculate
 *   GET    /apps/procest/api/cases/{caseId}/leges/audit-trail  — audit trail
 *   POST   /apps/procest/api/cases/{caseId}/leges/refund       — submit refund
 *
 * Verordening administration (admin):
 *   POST   /apps/procest/api/leges/import-verordening
 *   GET    /apps/procest/api/admin/leges/verordeningen
 *   PATCH  /apps/procest/api/admin/leges/verordeningen/{id}
 *   POST   /apps/procest/api/admin/leges/verordeningen/{id}/approve
 *
 * Uses @nextcloud/axios so CSRF tokens are attached automatically.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Build a Procest API URL.
 *
 * @param {string} path Sub-path under /apps/procest/api
 * @return {string} Fully qualified Nextcloud URL
 */
function url(path) {
	return generateUrl(`/apps/procest/api/${path}`)
}

/**
 * Get the current leges calculation for a case.
 *
 * @param {string} caseId The case UUID
 * @return {Promise<object|null>} The calculation or null when none exists
 */
export async function getLegesForCase(caseId) {
	try {
		const response = await axios.get(url(`cases/${caseId}/leges`))
		return response.data
	} catch (error) {
		if (error.response && error.response.status === 404) {
			return null
		}
		throw error
	}
}

/**
 * Trigger a (re)calculation for a case.
 *
 * @param {string} caseId The case UUID
 * @return {Promise<object>} The calculation result
 */
export async function calculateLeges(caseId) {
	const response = await axios.post(url(`cases/${caseId}/leges/calculate`), {})
	return response.data
}

/**
 * Get the audit trail for a case calculation.
 *
 * @param {string} caseId The case UUID
 * @return {Promise<object>} The audit trail
 */
export async function getAuditTrail(caseId) {
	const response = await axios.get(url(`cases/${caseId}/leges/audit-trail`))
	return response.data
}

/**
 * Submit a refund for a case calculation.
 *
 * @param {string} caseId The case UUID
 * @param {object} payload The refund payload {reason, fase}
 * @return {Promise<object>} The refund result
 */
export async function submitRefund(caseId, payload) {
	const response = await axios.post(url(`cases/${caseId}/leges/refund`), payload)
	return response.data
}

/**
 * List all tariff tables (admin).
 *
 * @return {Promise<Array>} The tariff tables
 */
export async function listVerordeningen() {
	const response = await axios.get(url('admin/leges/verordeningen'))
	return response.data.results || []
}

/**
 * Import a verordening from a decidesk raadsbesluit (admin).
 *
 * @param {object} payload The import payload {metaData, csv|tarieven}
 * @return {Promise<object>} The import result
 */
export async function importVerordening(payload) {
	const response = await axios.post(url('leges/import-verordening'), payload)
	return response.data
}

/**
 * Approve a concept tariff table (admin).
 *
 * @param {string} id The tariff table UUID
 * @return {Promise<object>} The approval result
 */
export async function approveVerordening(id) {
	const response = await axios.post(url(`admin/leges/verordeningen/${id}/approve`), {})
	return response.data
}
