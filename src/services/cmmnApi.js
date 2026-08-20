/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * CMMN adaptive-case-plan API service (cmmn-adaptive-case).
 *
 * Thin axios wrappers for the CMMN engine's REST surface
 * (`CmmnCaseController`), the counterpart to the status-transition engine's
 * consumer for CMMN-managed caseTypes.
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

/**
 * Build the base URL for one case's CMMN plan endpoints.
 *
 * @param {string} caseId The case UUID
 * @return {string} Base URL
 */
function baseUrl(caseId) {
	return generateUrl(`/apps/procest/api/case/${caseId}/cmmn-plan`)
}

/**
 * Fetch the current case plan.
 *
 * @param {string} caseId The case UUID
 * @return {Promise<object>} `{items, enableableDiscretionary, milestones, caseFile}`
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
 */
export async function fetchCasePlan(caseId) {
	const response = await axios.get(baseUrl(caseId))
	return response.data
}

/**
 * Enable a discretionary plan item.
 *
 * @param {string} caseId The case UUID
 * @param {string} itemId Plan-item id
 * @return {Promise<object>} The updated case plan
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-004
 */
export async function enableDiscretionaryItem(caseId, itemId) {
	const response = await axios.post(`${baseUrl(caseId)}/enable`, { itemId })
	return response.data
}

/**
 * Complete an active human task.
 *
 * @param {string} caseId The case UUID
 * @param {string} itemId Plan-item id
 * @return {Promise<object>} The updated case plan
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
 */
export async function completeTask(caseId, itemId) {
	const response = await axios.post(`${baseUrl(caseId)}/complete`, { itemId })
	return response.data
}

/**
 * Terminate a human task.
 *
 * @param {string} caseId The case UUID
 * @param {string} itemId Plan-item id
 * @return {Promise<object>} The updated case plan
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
 */
export async function terminateTask(caseId, itemId) {
	const response = await axios.post(`${baseUrl(caseId)}/terminate`, { itemId })
	return response.data
}

/**
 * Signal a case-file item change.
 *
 * @param {string} caseId The case UUID
 * @param {object} updates Case-file item id => new value
 * @return {Promise<object>} The updated case plan
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-003
 */
export async function signalCaseFileEvent(caseId, updates) {
	const response = await axios.post(`${baseUrl(caseId)}/signal`, { updates })
	return response.data
}
