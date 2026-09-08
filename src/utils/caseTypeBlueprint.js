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

/** The blueprint key, section id and heading of each list, in reading order. */
const SECTIONS = [
	{
		key: 'statusTypes',
		id: 'statuses',
		label: 'Statuses',
		emptyText: 'This case type has no statuses yet',
	},
	{
		key: 'resultTypes',
		id: 'results',
		label: 'Results',
		emptyText: 'This case type has no results yet',
	},
	{
		key: 'propertyDefinitions',
		id: 'properties',
		label: 'Attributes',
		emptyText: 'This case type has no attributes yet',
	},
]

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
 * @param {(key: string) => string} translate The host app's t().
 * @return {{badge: string, badgeTitle: string}} The badge and its title.
 */
function badgeFor(row, translate) {
	const origin = String(row?.origin ?? 'own')

	if (origin === 'inherited') {
		const parent = String(row?.originCaseTypeTitle ?? '')
		return {
			badge: translate('Inherited'),
			badgeTitle: parent
				? `${translate('Inherited from')} ${parent}`
				: translate('Inherited'),
		}
	}

	if (origin === 'shared') {
		return {
			badge: translate('Shared'),
			badgeTitle: translate('Shared across every case type'),
		}
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
 * @param {(key: string) => string} translate The host app's t().
 * @return {Array<object>} The sections.
 */
export function blueprintSections(blueprint, translate = (key) => key) {
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
				...badgeFor(row, translate),
			}))

		return {
			id: section.id,
			label: translate(section.label),
			emptyText: translate(section.emptyText),
			rows,
		}
	})
}

/**
 * The nearest parent this type inherits from.
 *
 * @param {object|null} blueprint The /blueprint answer.
 * @return {string} The parent's title, or '' when the type stands alone.
 */
export function parentTitleOf(blueprint) {
	const parents = Array.isArray(blueprint?.parents) ? blueprint.parents : []
	if (parents.length === 0) return ''

	return String(parents[0]?.title ?? '')
}
