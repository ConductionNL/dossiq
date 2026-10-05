// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The structure profile: two shapes of the same app, built from one manifest.
 *
 * dossiq ships `simple` and `full`. `full` is the navigation and the pages as
 * they were before this file existed. `simple` is what a case handler needs on
 * a working day: nine menu entries under three captions, with everything else
 * one level down. `simple` is the default, and an administrator brings `full`
 * back with the app setting `menu_structure`.
 *
 * A profile is a layout file next to the manifest:
 *
 *   src/menu-layout.json          full
 *   src/menu-layout.simple.json   simple
 *
 * Both hold the four keys the library's `buildManifest` already reads
 * (`relocations`, `removals`, `settingsSection`, `integrationsSection`). A
 * profile file may hold two more, which `buildManifest` has no word for and
 * this module applies around it:
 *
 *   menu    Entries merged BEFORE the manifest's own menu. `buildManifest`
 *           merges entries by id and the first definition of a key wins, so an
 *           entry that carries only `id` and `order` keeps its label, icon and
 *           route from the manifest and takes the order written here. An entry
 *           the manifest does not know is added as written.
 *   pages   Overlays on built pages, by id. `config` replaces the named
 *           config keys, `configAppend` appends items to a list in the config.
 *           An overlay never adds a page and never removes one.
 *
 * Nothing here deletes anything. The pages, the routes and the fragments are
 * the same in both profiles, which is what keeps every deep link working.
 *
 * @spec openspec/changes/simple-structure-profile/specs/nav-dedup-and-grouping/spec.md
 */

/** The profile a fresh instance gets. */
export const STRUCTURE_SIMPLE = 'simple'

/** The profile that keeps the navigation and pages as they were. */
export const STRUCTURE_FULL = 'full'

/** The app setting, and the initial-state key the page controller provides. */
export const STRUCTURE_SETTING = 'menu_structure'

/** The layout keys `buildManifest` reads. Everything else stays out of its way. */
const LAYOUT_KEYS = [
	'relocations',
	'removals',
	'settingsSection',
	'integrationsSection',
]

/**
 * The profile a stored value stands for.
 *
 * Only the exact word `full` selects the full structure. Anything else, an
 * unset key and a typing mistake included, is the simple one: the default has
 * to be the answer whenever the setting does not clearly say otherwise.
 *
 * @param {unknown} raw The stored setting, as initial state hands it over.
 * @return {string} `simple` or `full`.
 *
 * @spec openspec/changes/simple-structure-profile/specs/nav-dedup-and-grouping/spec.md#REQ-PNDG-007
 */
export function resolveStructureProfile(raw) {
	return raw === STRUCTURE_FULL ? STRUCTURE_FULL : STRUCTURE_SIMPLE
}

/**
 * Apply one page overlay to one built page, without touching the original.
 *
 * @param {object} page The built page.
 * @param {object} overlay `{ id, config?, configAppend? }`.
 * @return {object} A new page object.
 *
 * @spec openspec/changes/simple-structure-profile/specs/nav-dedup-and-grouping/spec.md#REQ-PNDG-008
 */
export function applyPageOverlay(page, overlay) {
	const config = { ...(page.config || {}), ...(overlay.config || {}) }
	const append = overlay.configAppend || {}
	for (const key of Object.keys(append)) {
		const current = Array.isArray(config[key]) ? config[key] : []
		const extra = Array.isArray(append[key]) ? append[key] : []
		config[key] = [...current, ...extra]
	}
	return { ...page, config }
}

/**
 * Build the manifest for one structure profile.
 *
 * `buildManifest` is passed in rather than imported, so this module stays free
 * of the library barrel and a spec can hand it the real implementation.
 *
 * A profile file without `menu` and `pages` (the full one) goes through
 * unchanged: the result is exactly `buildManifest(base, fragments, layout)`.
 *
 * An overlay that names a page the manifest does not have is skipped and
 * reported. It is a mistake in the profile file, and inventing the page here
 * would hide it.
 *
 * @param {(base: object, fragments: Array<object>, layout: object) => object} buildManifest
 *   The library's `buildManifest`.
 * @param {object} base The bundled manifest.
 * @param {Array<object>} fragments The `manifest.d` fragments, in order.
 * @param {object} profileFile The profile's layout file.
 * @return {object} The built manifest.
 *
 * @spec openspec/changes/simple-structure-profile/specs/nav-dedup-and-grouping/spec.md#REQ-PNDG-008
 */
export function buildProfiledManifest(buildManifest, base, fragments, profileFile) {
	const file = profileFile || {}
	const layout = {}
	for (const key of LAYOUT_KEYS) {
		if (file[key] !== undefined) {
			layout[key] = file[key]
		}
	}

	const profileMenu = Array.isArray(file.menu) ? file.menu : []
	const profiledBase =
		profileMenu.length > 0
			? {
					...base,
					// Copies, because buildManifest merges into the entries it is
					// given and the profile file is a shared module object.
					menu: [
						...profileMenu.map((entry) => ({ ...entry })),
						...(base.menu || []),
					],
				}
			: base

	const built = buildManifest(profiledBase, fragments, layout)

	const overlays = Array.isArray(file.pages) ? file.pages : []
	if (overlays.length === 0) {
		return built
	}
	const pages = [...(built.pages || [])]
	for (const overlay of overlays) {
		const at = pages.findIndex((page) => page.id === overlay?.id)
		if (at === -1) {
			// eslint-disable-next-line no-console
			console.warn(
				'[structureProfile] page overlay names a page the manifest does not have; skipped.',
				overlay?.id,
			)
			continue
		}
		pages[at] = applyPageOverlay(pages[at], overlay)
	}
	return { ...built, pages }
}
