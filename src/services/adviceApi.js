/**
 * Advice API service for Procest.
 *
 * CRUD lives on OpenRegister (manifest renderer pattern):
 *   GET    /apps/openregister/api/objects/procest/adviesAanvraag
 *   POST   /apps/openregister/api/objects/procest/adviesAanvraag
 *   GET    /apps/openregister/api/objects/procest/adviesAanvraag/{id}
 *   PUT    /apps/openregister/api/objects/procest/adviesAanvraag/{id}
 *   DELETE /apps/openregister/api/objects/procest/adviesAanvraag/{id}
 *
 * Workflow actions stay on the Procest controller:
 *   POST   /apps/procest/api/advice/{id}/transition  — fires notification
 *   POST   /apps/procest/api/advice/{id}/remind      — dispatches reminder
 *
 * Uses @nextcloud/axios so CSRF tokens are attached automatically.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const REGISTER = 'procest'
const SCHEMA = 'adviesAanvraag'
const OR_BASE = `apps/openregister/api/objects/${REGISTER}/${SCHEMA}`
const ACTION_BASE = 'apps/procest/api/advice'

/**
 * Build an OpenRegister objects URL.
 *
 * @param {string} path Optional sub-path (id)
 * @return {string} Fully qualified Nextcloud URL
 */
function orUrl(path = '') {
	const suffix = path ? `/${path}` : ''
	return generateUrl(`/${OR_BASE}${suffix}`)
}

/**
 * Build a Procest workflow-action URL.
 *
 * @param {string} path Sub-path (id/action)
 * @return {string} Fully qualified Nextcloud URL
 */
function actionUrl(path) {
	return generateUrl(`/${ACTION_BASE}/${path}`)
}

/**
 * Get advice requests for a case (via manifest renderer / OR filter).
 *
 * @param {string} caseId Case UUID
 * @return {Promise<Array>} List of advice records
 */
/**
 * @param caseId
 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
 */
export async function getAdviceForCase(caseId) {
	const response = await axios.get(orUrl(), {
		params: { _filters: JSON.stringify({ case: caseId }), _limit: 200 },
	})
	return response.data?.results || response.data || []
}

/**
 * Transition the status of an advice request.
 *
 * Use { to: 'aangevraagd' } right after creating an advice object to fire
 * the notification to the adviseur (workflow side-effect).
 *
 * Use { to: 'ontvangen', adviesDocument: '<fileId>' } to mark received.
 *
 * @param {string} id   Advice UUID
 * @param {object} body Transition payload (to, adviesDocument, ...)
 * @return {Promise<object>} Updated record
 */
/**
 * @param id
 * @param body
 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
 */
export async function transitionStatus(id, body) {
	const response = await axios.post(actionUrl(`${id}/transition`), body)
	return response.data
}

/**
 * Dispatch a manual reminder to the adviseur.
 *
 * @param {string} id Advice UUID
 * @return {Promise<object>} Server confirmation
 */
/**
 * @param id
 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
 */
export async function dispatchReminder(id) {
	const response = await axios.post(actionUrl(`${id}/remind`))
	return response.data
}

/**
 * Create an advice request (CRUD via manifest renderer) and fire the
 * "aangevraagd" notification via transitionStatus.
 *
 * Kept as a convenience for the case-detail dialog so callers do not need
 * to chain two requests manually.
 *
 * @param {object} data Advice payload (case, adviseur, type, deadline, ...)
 * @return {Promise<object>} Created record
 */
/**
 * Create an advice request and raise the decidesk advice Decision.
 *
 * Routes through the procest `advice#createForCase` controller endpoint
 * (AdviceService::requestAdvice) instead of writing the object directly to
 * OpenRegister, so the advice request raises a decidesk `advice` Decision via
 * the ADR-019 integration registry (procest-delegate-remaining-decisions-to-decidesk,
 * REQ-PDRD-001) and fails CLOSED server-side when decidesk is unavailable
 * (REQ-PDRD-002). The advice outcome is consumed as a projection.
 *
 * @param {object} data Advice payload (case, adviseur, type, deadline, ...)
 * @return {Promise<object>} Created record
 * @spec openspec/changes/retrofit-2026-05-24-advice-management/tasks.md
 * @spec openspec/specs/remaining-decision-delegation/spec.md
 */
export async function createAdviceWithNotification(data) {
	const caseId = data.case || data.caseRef || data.zaak
	const url = generateUrl('/apps/procest/api/vth/cases/{caseId}/advice-requests', { caseId })
	const created = await axios.post(url, {
		...data,
		requestedAt: new Date().toISOString(),
	})
	return created.data
}

export default {
	getAdviceForCase,
	createAdviceWithNotification,
	transitionStatus,
	dispatchReminder,
}
