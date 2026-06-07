import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const baseUrl = generateUrl('/apps/procest/api/beschikkingen')

/**
 * Compose a new beschikking from a case.
 *
 * @param {string} zaakId The case UUID.
 * @param {string} [templateId] Optional template id.
 * @param {object} [overrides] Optional field overrides (geadresseerde, motivering, ...).
 * @return {Promise<object>} The composed beschikking.
 * @spec openspec/changes/beschikking-generatie/tasks.md#T22
 */
export async function compose(zaakId, templateId = null, overrides = {}) {
	const payload = { zaakId, ...overrides }
	if (templateId) {
		payload.templateId = templateId
	}
	const response = await axios.post(baseUrl, payload)
	return response.data
}

/**
 * Fetch a single beschikking.
 *
 * @param {string} id The beschikking UUID.
 * @return {Promise<object>} The beschikking.
 * @spec openspec/changes/beschikking-generatie/tasks.md#T22
 */
export async function getBeschikking(id) {
	const response = await axios.get(`${baseUrl}/${id}`)
	return response.data
}

/**
 * Grant mandaat-approval.
 *
 * @param {string} id The beschikking UUID.
 * @return {Promise<object>} The updated beschikking.
 * @spec openspec/changes/beschikking-generatie/tasks.md#T22
 */
export async function akkoord(id) {
	const response = await axios.patch(`${baseUrl}/${id}/akkoord`, {})
	return response.data
}

/**
 * Sign the beschikking via a TSP.
 *
 * @param {string} id The beschikking UUID.
 * @param {string} tspProvider The TSP provider slug.
 * @return {Promise<object>} The updated beschikking.
 * @spec openspec/changes/beschikking-generatie/tasks.md#T22
 */
export async function onderteken(id, tspProvider) {
	const response = await axios.patch(`${baseUrl}/${id}/onderteken`, { tspProvider })
	return response.data
}

/**
 * Deliver the beschikking via Berichtenbox.
 *
 * @param {string} id The beschikking UUID.
 * @return {Promise<object>} The updated beschikking.
 * @spec openspec/changes/beschikking-generatie/tasks.md#T22
 */
export async function verzend(id) {
	const response = await axios.patch(`${baseUrl}/${id}/verzend`, {})
	return response.data
}

/**
 * Field-edit a beschikking (only allowed in the ontwerp status for content fields).
 *
 * @param {string} id The beschikking UUID.
 * @param {object} updates The field updates.
 * @return {Promise<object>} The updated beschikking.
 * @spec openspec/changes/beschikking-generatie/tasks.md#T22
 */
export async function updateFields(id, updates) {
	const response = await axios.patch(`${baseUrl}/${id}`, updates)
	return response.data
}

/**
 * Download the verifiable audit-pakket ZIP.
 *
 * @param {string} id The beschikking UUID.
 * @return {Promise<Blob>} The ZIP blob.
 * @spec openspec/changes/beschikking-generatie/tasks.md#T22
 */
export async function exportAuditPacket(id) {
	const response = await axios.get(`${baseUrl}/${id}/audit-pakket`, { responseType: 'blob' })
	return response.data
}

export default {
	compose,
	getBeschikking,
	akkoord,
	onderteken,
	verzend,
	updateFields,
	exportAuditPacket,
}
