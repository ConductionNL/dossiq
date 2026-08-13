/**
 * Substitution + bulk-reassignment API wrapper (handler-vervanging-waarneming).
 *
 * Thin axios client over the procest substitution endpoints. The backend
 * enforces all authorisation (own-record / coordinator) and OR RBAC; this
 * client only shapes requests and unwraps responses.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const base = (suffix = '') => generateUrl(`/apps/procest${suffix}`)

/**
 * List substitutions visible to the current user (coordinators see all).
 *
 * @return {Promise<Array>} The substitution records.
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
export async function listSubstitutions() {
	const { data } = await axios.get(base('/api/substitutions'))
	return (data && data.results) || []
}

/**
 * Create a substitution.
 *
 * @param {object} payload The substitution fields.
 * @return {Promise<object>} The created record.
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
export async function createSubstitution(payload) {
	const { data } = await axios.post(base('/api/substitutions'), payload)
	return data
}

/**
 * Revoke a substitution immediately.
 *
 * @param {string} id The substitution UUID.
 * @return {Promise<object>} The updated record.
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
export async function revokeSubstitution(id) {
	const { data } = await axios.post(
		base(`/api/substitutions/${encodeURIComponent(id)}/revoke`),
	)
	return data
}

/**
 * Fetch the substituted work routed to the current user (My Work integration).
 *
 * @return {Promise<{cases: Array, tasks: Array}>} Substituted cases + tasks.
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
export async function fetchSubstitutedWork() {
	const { data } = await axios.get(base('/api/substitutions/work'))
	return { cases: (data && data.cases) || [], tasks: (data && data.tasks) || [] }
}

/**
 * Fetch the capacity-stamped actions performed under a substitution.
 *
 * @param {string} id The substitution UUID.
 * @return {Promise<Array>} The stamped actions.
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
export async function fetchSubstitutionActions(id) {
	const { data } = await axios.get(
		base(`/api/substitutions/${encodeURIComponent(id)}/actions`),
	)
	return (data && data.results) || []
}

/**
 * Preview a bulk reassignment (coordinator-only, non-mutating).
 *
 * @param {string} fromUser The departing handler.
 * @param {string} [caseType] Optional case-type filter.
 * @return {Promise<{cases: Array, tasks: Array}>} The affected open items.
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
export async function previewReassignment(fromUser, caseType) {
	const { data } = await axios.post(base('/api/reassignments/preview'), {
		fromUser,
		caseType,
	})
	return { cases: (data && data.cases) || [], tasks: (data && data.tasks) || [] }
}

/**
 * Execute a bulk reassignment (coordinator-only).
 *
 * @param {string} fromUser The departing handler.
 * @param {string} toUser The receiving handler.
 * @param {string} [caseType] Optional case-type filter.
 * @return {Promise<object>} The batch result (batchId, results, succeeded, failed).
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
export async function executeReassignment(fromUser, toUser, caseType) {
	const { data } = await axios.post(base('/api/reassignments/execute'), {
		fromUser,
		toUser,
		caseType,
	})
	return data
}
