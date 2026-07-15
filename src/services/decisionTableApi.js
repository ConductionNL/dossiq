/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Decision-table API service (dmn-decision-tables).
 *
 * Thin axios wrapper for procest's DMN decision-table endpoints: admin-gated
 * CRUD plus the open-to-any-authenticated-user evaluate endpoint. Evaluation
 * itself is performed by the pure backend DecisionEngine; this module only
 * moves JSON over the wire.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/procest/api/decisions')

/**
 * List all decision tables.
 *
 * @return {Promise<object[]>} The decision tables
 * @spec openspec/changes/dmn-decision-tables/specs/dmn-decision-tables/spec.md
 */
export async function listDecisionTables() {
	const response = await axios.get(baseUrl)
	return response.data?.results || []
}

/**
 * Create a decision table (admin only server-side).
 *
 * @param {object} table The decision table payload
 * @return {Promise<object>} The saved table
 * @spec openspec/changes/dmn-decision-tables/specs/dmn-decision-tables/spec.md
 */
export async function createDecisionTable(table) {
	const response = await axios.post(baseUrl, table)
	return response.data
}

/**
 * Update a decision table (admin only server-side).
 *
 * @param {string} id The table id
 * @param {object} table The decision table payload
 * @return {Promise<object>} The saved table
 * @spec openspec/changes/dmn-decision-tables/specs/dmn-decision-tables/spec.md
 */
export async function updateDecisionTable(id, table) {
	const response = await axios.put(`${baseUrl}/${id}`, table)
	return response.data
}

/**
 * Delete a decision table (admin only server-side).
 *
 * @param {string} id The table id
 * @return {Promise<void>}
 * @spec openspec/changes/dmn-decision-tables/specs/dmn-decision-tables/spec.md
 */
export async function deleteDecisionTable(id) {
	await axios.delete(`${baseUrl}/${id}`)
}

/**
 * Evaluate a decision table against a set of inputs.
 *
 * @param {string} id The table id
 * @param {object} inputs The input values keyed by input name
 * @return {Promise<object>} `{outputs, matchedRuleIds, hitPolicy}`
 * @spec openspec/changes/dmn-decision-tables/specs/dmn-decision-tables/spec.md
 */
export async function evaluateDecisionTable(id, inputs) {
	const response = await axios.post(`${baseUrl}/${id}/evaluate`, inputs)
	return response.data
}
