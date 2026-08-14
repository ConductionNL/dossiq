/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure presentation helpers for the CMMN case-plan panel (cmmn-adaptive-case).
 * No network, no Vue — vitest-testable. Mirrors the read-only-plus-actions
 * shape `CaseModelEngine::buildPlanView()` returns: a flat `items[]` list
 * (each `{id, type, name, discretionary, parentId, state}`) plus
 * `enableableDiscretionary[]`, `milestones{}`, `caseFile{}`.
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md
 */

/**
 * Plan-item states that never change again once reached, mirroring
 * `PlanItemTransitions::TERMINAL_STATES` on the backend.
 *
 * @type {string[]}
 */
export const TERMINAL_STATES = ['completed', 'terminated', 'disabled']

/**
 * Build a tree from the flat `items[]` list, nesting each item under its
 * `parentId`. Items whose `parentId` does not resolve to another item in the
 * list (including `null`/absent) become top-level roots — defensive against
 * a partially-loaded list, never throws.
 *
 * @param {Array<object>} items Flat plan-item list from `fetchCasePlan()`.
 * @return {Array<object>} Top-level items, each carrying a `children` array.
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-001
 */
export function buildPlanTree(items) {
	const list = Array.isArray(items) ? items : []
	const byId = {}
	list.forEach((item) => {
		byId[item.id] = { ...item, children: [] }
	})

	const roots = []
	list.forEach((item) => {
		const node = byId[item.id]
		const parent = item.parentId ? byId[item.parentId] : null
		if (parent) {
			parent.children.push(node)
		} else {
			roots.push(node)
		}
	})

	return roots
}

/**
 * Badge label + CSS modifier class for a plan-item state, for consistent
 * rendering across the panel.
 *
 * @param {string} state One of available|enabled|active|completed|terminated|disabled.
 * @return {{label: string, cssClass: string}} Display label and CSS class suffix.
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-002
 */
export function stateBadge(state) {
	const labels = {
		available: t('procest', 'Available'),
		enabled: t('procest', 'Enabled'),
		active: t('procest', 'In progress'),
		completed: t('procest', 'Completed'),
		terminated: t('procest', 'Terminated'),
		disabled: t('procest', 'Disabled'),
	}

	return {
		label: labels[state] || state || '-',
		cssClass: `cmmn-plan-panel__badge--${state || 'unknown'}`,
	}
}

/**
 * Whether a plan item is currently terminal (no further transitions possible).
 *
 * @param {object} item A plan item `{state, ...}`.
 * @return {boolean} True when terminal.
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-002
 */
export function isTerminalItem(item) {
	return TERMINAL_STATES.includes(item?.state)
}

/**
 * Whether a discretionary item may currently be enabled by the worker.
 *
 * @param {object} item A plan item `{id, discretionary, ...}`.
 * @param {string[]} enableableDiscretionary The engine's `enableableDiscretionary[]` list.
 * @return {boolean} True when the "enable" action should be offered.
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-004
 */
export function isEnableable(item, enableableDiscretionary) {
	if (!item || item.discretionary !== true) {
		return false
	}
	return (
		Array.isArray(enableableDiscretionary)
		&& enableableDiscretionary.includes(item.id)
	)
}

/**
 * Whether a human task may currently be completed by the worker.
 *
 * @param {object} item A plan item `{type, state}`.
 * @return {boolean} True when the "complete" action should be offered.
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
 */
export function canComplete(item) {
	return item?.type === 'humanTask' && item?.state === 'active'
}

/**
 * Whether a human task may currently be terminated by the worker (started
 * or ready-to-start, not yet finished).
 *
 * @param {object} item A plan item `{type, state}`.
 * @return {boolean} True when the "terminate" action should be offered.
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-007
 */
export function canTerminate(item) {
	return (
		item?.type === 'humanTask'
		&& (item?.state === 'active' || item?.state === 'enabled')
	)
}

/**
 * Whether a milestone has been achieved.
 *
 * @param {object} milestones The engine's `milestones{}` map keyed by plan-item id.
 * @param {string} itemId Plan-item id.
 * @return {boolean} True when achieved.
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-005
 */
export function isMilestoneAchieved(milestones, itemId) {
	return (milestones || {})[itemId]?.achieved === true
}
