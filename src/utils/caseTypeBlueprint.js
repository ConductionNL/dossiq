// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// Shaping for the case type's blueprint panel.
//
// `GET /api/case-types/{id}/blueprint` answers the MERGED lists — the type's
// own rows and its ancestors' — with an `origin` on every row saying which.
// These functions turn that into what the panel renders, and they are pure so
// the shaping can be tested without a server or a mount.
//
// The badge is the point of the whole panel. A child type that declares
// nothing looks, on screen, exactly like a type that declared four statuses
// itself; without the badge an author would edit an inherited row expecting it
// to change only this type. So a row that came from an ancestor SAYS SO, and
// names the ancestor in its title attribute.
//
// @spec openspec/specs/case-types/spec.md
// @spec openspec/specs/property-definition-management/spec.md

/**
 * The blueprint key, section id and label keys of each list, in reading order.
 *
 * The LABELS are not here. They arrive as an already-translated `labels`
 * object, because `tests/l10n/check-l10n.js` extracts a translatable string by
 * finding a literal inside a `t()` call for this app: a string that lives in
 * this file and is handed to a translate callback is invisible to it, so it
 * would never reach l10n/en.json, never reach a translator, and render in
 * English to a Dutch reader with every check green.
 */
const SECTIONS = [
	{ key: 'statusTypes', id: 'statuses' },
	{ key: 'resultTypes', id: 'results' },
	{ key: 'propertyDefinitions', id: 'properties' },
]

/**
 * The English fallbacks, for a caller that hands over no labels.
 *
 * They exist so a missing label renders a word rather than `undefined`, which
 * is what a reader would otherwise see where a heading belongs.
 */
const DEFAULT_LABELS = {
	statuses: 'Statuses',
	results: 'Results',
	properties: 'Attributes',
	statusesEmpty: 'This case type has no statuses yet',
	resultsEmpty: 'This case type has no results yet',
	propertiesEmpty: 'This case type has no attributes yet',
	inherited: 'Inherited',
	inheritedFrom: 'Inherited from',
	shared: 'Shared',
	sharedTitle: 'Shared across every case type',
}

/**
 * The row's display name.
 *
 * @param {object} row A blueprint row.
 * @return {string} Its name, its title, or '' when it has neither.
 */
function nameOf(row) {
	return String(row?.name ?? row?.title ?? '')
}

/**
 * The row's id, whichever shape the server answered in.
 *
 * @param {object} row A blueprint row.
 * @return {string} The id, or '' when it has none.
 */
function idOf(row) {
	return String(row?.id ?? row?.['@self']?.id ?? '')
}

/**
 * The badge a row carries, by where it came from.
 *
 * A row the type declared itself carries NO badge: badging every row would
 * make the two cases equally loud, and the one worth noticing is the
 * inherited one.
 *
 * @param {object} row A blueprint row.
 * @param {object} labels The already-translated labels.
 * @return {{badge: string, badgeTitle: string}} The badge and its title.
 */
function badgeFor(row, labels) {
	const origin = String(row?.origin ?? 'own')

	if (origin === 'inherited') {
		const parent = String(row?.originCaseTypeTitle ?? '')
		return {
			badge: labels.inherited,
			badgeTitle: parent
				? `${labels.inheritedFrom} ${parent}`
				: labels.inherited,
		}
	}

	if (origin === 'shared') {
		return { badge: labels.shared, badgeTitle: labels.sharedTitle }
	}

	return { badge: '', badgeTitle: '' }
}

/**
 * The panel's three sections, each with its rows shaped for rendering.
 *
 * Rows with no name at all are dropped: an unnamed row renders as a bare
 * badge, which tells a reader nothing and looks like a rendering fault.
 *
 * @param {object|null} blueprint The /blueprint answer.
 * @param {object} labels The already-translated labels: `statuses`, `results`,
 *   `properties`, `statusesEmpty`, `resultsEmpty`, `propertiesEmpty`,
 *   `inherited`, `inheritedFrom`, `shared` and `sharedTitle`.
 * @return {Array<object>} The sections.
 *
 * @spec openspec/specs/case-types/spec.md
 */
export function blueprintSections(blueprint, labels = {}) {
	const text = { ...DEFAULT_LABELS, ...labels }

	return SECTIONS.map((section) => {
		const raw = Array.isArray(blueprint?.[section.key])
			? blueprint[section.key]
			: []

		const rows = raw
			.filter((row) => nameOf(row) !== '')
			.map((row, index) => ({
				key: idOf(row) || `${section.id}-${index}`,
				name: nameOf(row),
				origin: String(row?.origin ?? 'own'),
				...badgeFor(row, text),
			}))

		return {
			id: section.id,
			label: text[section.id],
			emptyText: text[`${section.id}Empty`],
			rows,
		}
	})
}

/**
 * The nearest parent this type inherits from.
 *
 * @param {object|null} blueprint The /blueprint answer.
 * @return {string} The parent's title, or '' when the type stands alone.
 *
 * @spec openspec/specs/case-types/spec.md
 */
export function parentTitleOf(blueprint) {
	const parents = Array.isArray(blueprint?.parents) ? blueprint.parents : []
	if (parents.length === 0) return ''

	return String(parents[0]?.title ?? '')
}
