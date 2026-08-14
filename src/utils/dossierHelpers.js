/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Pure helpers for the ZGW DRC case dossier: confidentiality hierarchy
 * ordering, share-eligibility (mirrors the server-side publish threshold),
 * status-transition validation (forward-only concept -> definitief ->
 * gearchiveerd), and human-readable byte sizes. Kept DOM-free so the exact
 * logic can be unit-tested without rendering a component.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
 */

/**
 * ZGW confidentiality levels ordered lowest (index 0) to highest.
 *
 * @type {string[]}
 */
export const CONFIDENTIALITY_HIERARCHY = [
	'openbaar',
	'beperkt_openbaar',
	'intern',
	'zaakvertrouwelijk',
	'vertrouwelijk',
	'confidentieel',
	'geheim',
	'zeer_geheim',
]

/**
 * Classification at or above which a public share is forbidden.
 *
 * @type {string}
 */
export const PUBLISH_THRESHOLD = 'vertrouwelijk'

/**
 * Allowed forward-only status transitions.
 *
 * @type {Object<string, string[]>}
 */
export const STATUS_TRANSITIONS = {
	concept: ['definitief'],
	definitief: ['gearchiveerd'],
	gearchiveerd: [],
}

/**
 * Map a confidentiality level to its ordinal; unknown maps to the most
 * restrictive (fail-closed), matching the backend guard.
 *
 * @param {string} level The confidentiality level.
 * @return {number} The ordinal index.
 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
 */
export function confidentialityOrdinal(level) {
	const index = CONFIDENTIALITY_HIERARCHY.indexOf(level)
	return index === -1 ? CONFIDENTIALITY_HIERARCHY.length - 1 : index
}

/**
 * Whether a document may be publicly shared (below the vertrouwelijk threshold).
 *
 * @param {string} level The document's confidentiality level.
 * @return {boolean} True when shareable.
 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
 */
export function canShare(level) {
	return confidentialityOrdinal(level) < confidentialityOrdinal(PUBLISH_THRESHOLD)
}

/**
 * Whether a status transition is permitted (forward-only).
 *
 * @param {string} from The current status.
 * @param {string} to The requested status.
 * @return {boolean} True when allowed.
 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
 */
export function isTransitionAllowed(from, to) {
	if (from === to) {
		return false
	}
	const allowed = STATUS_TRANSITIONS[from] || []
	return allowed.indexOf(to) !== -1
}

/**
 * Whether a requested classification is allowed given a type default
 * (equal or more restrictive only).
 *
 * @param {string} defaultLevel The type default classification.
 * @param {string} requestedLevel The requested classification.
 * @return {boolean} True when allowed.
 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
 */
export function isClassificationAllowed(defaultLevel, requestedLevel) {
	if (!requestedLevel) {
		return true
	}
	return (
		confidentialityOrdinal(requestedLevel)
		>= confidentialityOrdinal(defaultLevel)
	)
}

/**
 * Group documents by informatieobjecttype with counts.
 *
 * @param {Array} documents The documents to group.
 * @return {Array} A list of { informatieobjecttype, count, documents }.
 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
 */
export function groupByType(documents) {
	const groups = {}
	documents.forEach((doc) => {
		const type = doc.informatieobjecttype || 'onbekend'
		if (!groups[type]) {
			groups[type] = []
		}
		groups[type].push(doc)
	})
	return Object.keys(groups).map((type) => ({
		informatieobjecttype: type,
		count: groups[type].length,
		documents: groups[type],
	}))
}

/**
 * Format a byte count for display.
 *
 * @param {number} bytes The size in bytes.
 * @return {string} A human-readable size.
 * @spec openspec/changes/document-zaakdossier/tasks.md#T06
 */
export function formatSize(bytes) {
	const value = Number(bytes) || 0
	if (value < 1024) {
		return value + ' B'
	}
	if (value < 1024 * 1024) {
		return (value / 1024).toFixed(1) + ' KB'
	}
	return (value / (1024 * 1024)).toFixed(1) + ' MB'
}
