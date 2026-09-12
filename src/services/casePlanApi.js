/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The adaptive case plan, read from OpenRegister's case layer.
 *
 * This is the replacement for `cmmnApi.js`, which talks to dossiq's own CMMN
 * engine on `/apps/dossiq/api/case/{id}/cmmn-plan*`. That engine retires in
 * group 3 of retire-cmmn-caseplanstate; until the drain report is clean, both
 * clients ship and `decidePlanSource()` picks between them.
 *
 * OpenRegister answers a plan as a FLAT list of items with `parentItemId`
 * pointing at another item's numeric `id`. Everything the panel shows as a
 * tree is shaped here, so the shaping is testable without a browser.
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
 */
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'

/** The six plan-item states, in lifecycle order. */
export const PLAN_ITEM_STATES = [
	'available',
	'enabled',
	'active',
	'completed',
	'terminated',
	'disabled',
]

/** States nothing can transition out of. */
export const TERMINAL_STATES = ['completed', 'terminated', 'disabled']

/**
 * Base URL of OpenRegister's case layer.
 *
 * @param {string} path Path under `/api/cases`, with no leading slash.
 * @return {string} The absolute URL.
 */
function casesUrl(path) {
	return generateUrl(`/apps/openregister/api/cases/${path}`)
}

/**
 * Read the plan OpenRegister holds for a case.
 *
 * @param {string} objectUuid The case object uuid.
 * @return {Promise<object>} `{objectUuid, settings, items, audit}`
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
export async function fetchCasePlan(objectUuid) {
	const response = await axios.get(casesUrl(objectUuid))
	return response.data
}

/**
 * Transition one plan item.
 *
 * OpenRegister judges whether the transition is legal and refuses it naming
 * item, type, from-state and to-state. dossiq relays that refusal and does not
 * hold a second opinion.
 *
 * @param {string} itemUuid The plan item's uuid.
 * @param {string} to       The target state.
 * @param {string} reason   Free text, optional.
 * @return {Promise<object>} The item as persisted.
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
 */
export async function transitionPlanItem(itemUuid, to, reason = '') {
	const response = await axios.post(casesUrl(`items/${itemUuid}/transition`), { to, reason })
	return response.data
}

/**
 * Enable a discretionary plan item.
 *
 * @param {string} itemUuid The plan item's uuid.
 * @return {Promise<object>} The item as persisted.
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
 */
export async function enablePlanItem(itemUuid) {
	const response = await axios.post(casesUrl(`items/${itemUuid}/enable`), {})
	return response.data
}

/**
 * List the discretionary items the caller may enable right now.
 *
 * @param {string} objectUuid The case object uuid.
 * @return {Promise<Array<object>>} The enableable items.
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
 */
export async function fetchEnableableItems(objectUuid) {
	const response = await axios.get(casesUrl(`${objectUuid}/enableable`))
	return response.data?.results ?? []
}

/**
 * Attach an ad-hoc item to a running case.
 *
 * Capability dossiq's own engine never had: an item nobody planned, added to
 * the case that needs it.
 *
 * @param {string} objectUuid The case object uuid.
 * @param {object} item       `{key, type, name, parent, ...}`
 * @return {Promise<object>} The attached item.
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
 */
export async function attachAdHocItem(objectUuid, item) {
	const response = await axios.post(casesUrl(`${objectUuid}/items`), item)
	return response.data
}

/**
 * Whether a plan response actually carries rows.
 *
 * An empty `items` list is NOT the same as a plan: it is a case OpenRegister
 * holds nothing for. The panel must never render that as "this case has no
 * work", because the local engine may still hold the real plan in its blob.
 *
 * @param {object|null} plan A plan response, or null.
 * @return {boolean} True when OpenRegister holds at least one row.
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
export function hasPlanRows(plan) {
	return Array.isArray(plan?.items) && plan.items.length > 0
}

/**
 * Shape the flat item list into stages with their children.
 *
 * Root items (no `parentItemId`) come first in declared `position` order; each
 * stage carries its direct children in the same order. Depth stops at the
 * items OpenRegister returned, so an item whose parent is invisible to this
 * caller surfaces at the root rather than disappearing.
 *
 * @param {Array<object>} items The flat `items` list from a plan response.
 * @return {Array<object>} Root nodes, each with a `children` array.
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
 */
export function groupPlanByStage(items) {
	const rows = Array.isArray(items) ? items : []
	const byId = new Map(rows.map((row) => [row.id, { ...row, children: [] }]))
	const roots = []

	for (const row of rows) {
		const node = byId.get(row.id)
		const parent = row.parentItemId === null || row.parentItemId === undefined
			? undefined
			: byId.get(row.parentItemId)

		if (parent === undefined) {
			roots.push(node)
			continue
		}

		parent.children.push(node)
	}

	const byPosition = (a, b) => (a.position ?? 0) - (b.position ?? 0)
	for (const node of byId.values()) {
		node.children.sort(byPosition)
	}

	return roots.sort(byPosition)
}

/**
 * Which transitions the panel offers for one item.
 *
 * Advisory only. OpenRegister decides, and a button this function offers can
 * still be refused: the refusal is the answer, not a bug in this list.
 *
 * @param {object} item One plan item.
 * @return {Array<string>} Target states to offer.
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-005-the-case-detail-renders-the-openregister-plan
 */
export function offeredTransitions(item) {
	if (TERMINAL_STATES.includes(item?.state)) {
		return []
	}

	if (item?.type === 'milestone') {
		return item?.state === 'available' ? ['completed', 'terminated'] : []
	}

	if (item?.state === 'active') {
		return ['completed', 'terminated']
	}

	return ['terminated']
}

/**
 * The message a failed plan request shows the caseworker.
 *
 * Fails CLOSED. There is no wording here that could be read as "this case has
 * no plan", because an unreachable case layer and an empty case look identical
 * from the browser and only one of them is safe to act on.
 *
 * @param {object} error An axios error.
 * @return {string} The message.
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-001-case-semantics-are-consumed-from-openregister
 */
export function planErrorMessage(error) {
	const status = error?.response?.status
	const detail = error?.response?.data?.error ?? error?.response?.data?.message

	if (status === 403) {
		return t('dossiq', 'You are not allowed to see the plan for this case.')
	}

	if (typeof detail === 'string' && detail !== '') {
		return detail
	}

	return t('dossiq', 'The case plan could not be loaded. Try again.')
}
