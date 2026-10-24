// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
/**
 * Lenses that name an app setting.
 *
 * A quick filter may take a value from the app config (`@config.<key>`, which
 * nextcloud-vue resolves in a lens's filter and labels). A required token
 * whose setting is empty would filter on the literal token and label the tab
 * with it, so such a lens is left out until the setting has a value. A lens
 * that was the default hands that to the first lens left.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

/** A required `@config.<key>` token; a trailing `?` makes it optional. */
const CONFIG_TOKEN = /@config\.([A-Za-z0-9_.-]*[A-Za-z0-9_])(\?)?/g

/**
 * The config keys a lens needs, from its labels and filter.
 *
 * @param {object} lens A quick filter.
 * @return {Array<string>} The keys of its required tokens.
 */
function requiredKeys(lens) {
	const text = JSON.stringify([lens.label, lens.activeLabel, lens.filter])
	return [...text.matchAll(CONFIG_TOKEN)].filter((match) => !match[2]).map((match) => match[1])
}

/**
 * Whether the config holds a value at a dotted key.
 *
 * @param {object} config The app config.
 * @param {string} key The key.
 * @return {boolean}
 */
function holds(config, key) {
	const value = key.split('.').reduce((at, part) => (at && typeof at === 'object' ? at[part] : undefined), config)
	return value !== undefined && value !== null && String(value).trim() !== ''
}

/**
 * Leave out each default filter whose setting is empty, and the map when none
 * is left, so the list opens unfiltered rather than on the literal token.
 *
 * @param {object|undefined} pageConfig A page's config; changed in place.
 * @param {object} config The app config.
 * @return {void}
 */
function dropUnsetDefaultFilters(pageConfig, config) {
	const defaults = pageConfig?.defaultFilters
	if (!defaults || typeof defaults !== 'object' || Array.isArray(defaults)) {
		return
	}
	const kept = Object.fromEntries(Object.entries(defaults).filter(([, value]) => requiredKeys({ filter: value }).every((key) => holds(config, key))))
	if (Object.keys(kept).length === 0) {
		delete pageConfig.defaultFilters
	} else {
		pageConfig.defaultFilters = kept
	}
}

/**
 * The manifest with every lens and default filter whose setting is empty left out.
 *
 * @param {object} manifest The manifest; its pages are changed in place.
 * @param {object} config The app config (`loadState('dossiq', 'config')`).
 * @return {object} The manifest.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function withConfiguredLenses(manifest, config) {
	const values = config && typeof config === 'object' ? config : {}
	for (const page of manifest?.pages ?? []) {
		dropUnsetDefaultFilters(page?.config, values)
		const lenses = page?.config?.quickFilters
		if (!Array.isArray(lenses)) {
			continue
		}
		const kept = lenses.filter((lens) => !lens || typeof lens !== 'object' || requiredKeys(lens).every((key) => holds(values, key)))
		if (kept.length === lenses.length) {
			continue
		}
		if (kept.length > 0 && !kept.some((lens) => lens && lens.default === true)) {
			kept[0] = { ...kept[0], default: true }
		}
		page.config.quickFilters = kept
	}
	return manifest
}
