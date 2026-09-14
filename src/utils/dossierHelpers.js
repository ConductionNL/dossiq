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
	draft: ['final'],
	final: ['archived'],
	archived: [],
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
 * Confidentiality dropdown options, lowest to highest, shared by the upload
 * metadata dialog and the bulk confidentiality-change dialog so the two
 * pickers cannot drift apart.
 *
 * @param {(app: string, text: string) => string} t The bound translate function.
 * @return {Array<{id: string, label: string}>} The options.
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
export function classificationOptions(t) {
	const labels = {
		openbaar: t('dossiq', 'Public'),
		beperkt_openbaar: t('dossiq', 'Limited public'),
		intern: t('dossiq', 'Internal'),
		zaakvertrouwelijk: t('dossiq', 'Case-confidential'),
		vertrouwelijk: t('dossiq', 'Confidential'),
		confidentieel: t('dossiq', 'Restricted'),
		geheim: t('dossiq', 'Secret'),
		zeer_geheim: t('dossiq', 'Top secret'),
	}
	return CONFIDENTIALITY_HIERARCHY.map((id) => ({ id, label: labels[id] }))
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
		const type = doc.informatieobjecttype || 'unknown'
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

/**
 * The direction values `informatieobject.direction` accepts, in the order the
 * metadata dialog offers them. Mirrors the enum in the register fragment
 * `lib/Settings/register.d/70-document-zaakdossier.json`.
 *
 * @type {string[]}
 */
export const DOCUMENT_DIRECTIONS = ['incoming', 'outgoing', 'internal']

/**
 * The default direction, matching the schema default.
 *
 * A document back-filled before the property existed carries none, and reads
 * as Internal until someone edits it — which is what the schema would have
 * written anyway.
 *
 * @type {string}
 */
export const DEFAULT_DIRECTION = 'internal'

/**
 * The keywords of one document, always as an array of non-empty strings.
 *
 * `keywords` is optional, so a document may carry `undefined`, and a document
 * written before the property existed carries nothing at all. Neither is an
 * error and neither may throw in a render.
 *
 * @param {object} document The informatieobject.
 * @return {string[]} Its keywords, possibly empty.
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
export function documentKeywords(document) {
	const raw = (document || {}).keywords
	if (!Array.isArray(raw)) {
		return []
	}
	return raw
		.filter((keyword) => typeof keyword === 'string')
		.map((keyword) => keyword.trim())
		.filter((keyword) => keyword !== '')
}

/**
 * Every keyword in use across the dossier, deduplicated and sorted.
 *
 * This is the facet the tab's keyword filter offers. It is computed from the
 * rows the dossier endpoint returned rather than asked of OpenRegister,
 * because the endpoint groups by type and takes no keyword parameter — so
 * the filter narrows what is already on screen.
 *
 * @param {Array} groups The dossier groups.
 * @return {string[]} The keywords in use.
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
export function collectKeywords(groups) {
	const seen = new Set()
	;(groups || []).forEach((group) => {
		;(group.documents || []).forEach((document) => {
			documentKeywords(document).forEach((keyword) => seen.add(keyword))
		})
	})
	return Array.from(seen).sort((a, b) => a.localeCompare(b))
}

/**
 * Narrow the dossier groups to the documents carrying every chosen keyword.
 *
 * An empty selection is not a filter: it returns the groups untouched, which
 * is what clearing the filter has to do. A group left with no documents is
 * dropped, so an empty type heading never survives a filter.
 *
 * @param {Array} groups The dossier groups.
 * @param {string[]} keywords The chosen keywords.
 * @return {Array} The narrowed groups.
 * @spec openspec/specs/document-zaakdossier/spec.md
 */
export function filterGroupsByKeywords(groups, keywords) {
	const wanted = (keywords || []).filter((keyword) => keyword !== '')
	if (wanted.length === 0) {
		return groups || []
	}
	return (groups || [])
		.map((group) => {
			const documents = (group.documents || []).filter((document) => {
				const own = documentKeywords(document)
				return wanted.every((keyword) => own.includes(keyword))
			})
			return { ...group, documents, count: documents.length }
		})
		.filter((group) => group.documents.length > 0)
}
