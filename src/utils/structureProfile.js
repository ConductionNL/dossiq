// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The structure profile: two shapes of the same app, built from one manifest.
 *
 * dossiq ships `simple` and `full`. `full` is the navigation and the pages as
 * they were before this file existed. `simple` is what a case handler needs on
 * a working day: nine menu entries, two of the three groups under a caption, with everything else
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
 *           config keys (a `null` takes the key out, for a pane the page
 *           opts into by declaring it), `configPatch` changes items of a list
 *           by name (a `null` takes the item out), `configAppend` appends
 *           items, and `configOrder` moves the named items to the front. They
 *           apply in that order. A name is the item's `id`, else its `key`,
 *           else its `label`. `slots` adds entries to the page's slot map. An
 *           overlay never adds a page and never removes one.
 *   nav     Merged over the manifest's `nav` (the brand block and the primary
 *           action CnAppNav draws). A string value `@theming.<key>` is read
 *           from the instance's theming capabilities (`name`, `logo`, ...),
 *           so a profile can show the municipality's own name and logo
 *           without naming one. A placeholder the instance cannot answer is
 *           left empty, never invented.
 *
 * Nothing here deletes anything. The pages, the routes and the fragments are
 * the same in both profiles, which is what keeps every deep link working.
 *
 * @spec openspec/specs/nav-dedup-and-grouping/spec.md
 */

import { mergeManifestDelta } from '@conduction/nextcloud-vue/src/utils/mergeManifestDelta.js'
import logger from '../logger.js'

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
 * @spec openspec/specs/nav-dedup-and-grouping/spec.md#REQ-PNDG-007
 */
export function resolveStructureProfile(raw) {
	return raw === STRUCTURE_FULL ? STRUCTURE_FULL : STRUCTURE_SIMPLE
}

/**
 * Apply one page overlay to one built page, without touching the original.
 *
 * @param {object} page The built page.
 * @param {object} overlay `{ id, title?, config?, configPatch?, configAppend?, configOrder? }`.
 *   The order is fixed: replace keys, patch items by name, append, then order.
 *   A patch replaces the keys it names on its item, except on a default view,
 *   where it merges deeply as the library's manifest delta does (objects key
 *   by key, arrays and scalars replace), so a view's `overrides` can be
 *   patched one key at a time.
 * @return {object} A new page object.
 *
 * @spec openspec/specs/nav-dedup-and-grouping/spec.md#REQ-PNDG-008
 */
export function applyPageOverlay(page, overlay) {
	const config = replacedConfig(page, overlay)
	patchItems(config, overlay.configPatch || {})
	appendItems(config, overlay.configAppend || {})
	orderItems(config, overlay.configOrder || {})
	return { ...page, ...overlayTitle(overlay), config, ...overlaySlots(page, overlay) }
}

/**
 * The title an overlay renames its page to, as a top-level key. The page
 * title is a top-level key, and the renderer lets it win over any
 * `config.title`, so an overlay that renames a page names it here.
 *
 * @param {object} overlay The overlay.
 * @return {{title?: string}} The title, or nothing.
 */
function overlayTitle(overlay) {
	return typeof overlay.title === 'string' && overlay.title !== ''
		? { title: overlay.title }
		: {}
}

/**
 * The page's slots with the overlay's added. A `custom` widget resolves
 * through the page's own top-level `slots` map, so a page that gains one
 * needs its slot beside it.
 *
 * @param {object} page The built page.
 * @param {object} overlay The overlay.
 * @return {{slots?: object}} The slots, or nothing when the overlay adds none.
 */
function overlaySlots(page, overlay) {
	if (!overlay.slots || typeof overlay.slots !== 'object') {
		return {}
	}
	return { slots: { ...(page.slots || {}), ...overlay.slots } }
}

/**
 * The page config with the overlay's keys replaced.
 *
 * @param {object} page The built page.
 * @param {object} overlay The overlay.
 * @return {object} A new config object.
 */
function replacedConfig(page, overlay) {
	const config = { ...(page.config || {}), ...(overlay.config || {}) }
	for (const key of Object.keys(overlay.config || {})) {
		// A key set to null leaves the config: the folder pane of the cases
		// list is such a key, an object the page opts into by declaring it,
		// and the schema allows no "off" value for it.
		if (overlay.config[key] === null) {
			delete config[key]
		}
	}
	return config
}

/**
 * The list a config key holds, or an empty list.
 *
 * @param {unknown} value The config value.
 * @return {Array} The list.
 */
function listOf(value) {
	return Array.isArray(value) ? value : []
}

/**
 * Patch the named items of each listed config key; a null patch removes
 * the item.
 *
 * @param {object} config The config being built.
 * @param {object} patch Per config key, the changes by item name.
 */
function patchItems(config, patch) {
	for (const key of Object.keys(patch)) {
		const byName = patch[key] || {}
		config[key] = listOf(config[key])
			.filter((item) => byName[overlayItemName(item)] !== null)
			.map((item) => patchedItem({ key, item }, byName[overlayItemName(item)]))
	}
}

/**
 * One list item with its change applied, untouched without one or when it
 * is not an object.
 *
 * @param {{key: string, item: unknown}} listed The config key and the item.
 * @param {object|undefined} change The change for the item.
 * @return {unknown} The item.
 */
function patchedItem(listed, change) {
	return change === undefined || typeof listed.item !== 'object'
		? listed.item
		: mergePatch(listed.key, listed.item, change)
}

/**
 * Append the overlay's items to each listed config key.
 *
 * @param {object} config The config being built.
 * @param {object} append Per config key, the items to append.
 */
function appendItems(config, append) {
	for (const key of Object.keys(append)) {
		config[key] = [...listOf(config[key]), ...listOf(append[key])]
	}
}

/**
 * Move the named items of each listed config key to the front, in the
 * order named; names that match nothing are skipped.
 *
 * @param {object} config The config being built.
 * @param {object} order Per config key, the item names to lead with.
 */
function orderItems(config, order) {
	for (const key of Object.keys(order)) {
		const current = listOf(config[key])
		const lead = listOf(order[key])
			.map((name) => current.find((item) => overlayItemName(item) === name))
			.filter((item) => item !== undefined)
		config[key] = [...lead, ...current.filter((item) => !lead.includes(item))]
	}
}

/** Config lists whose items a patch merges into deeply. */
const DEEP_PATCHED_LISTS = ['defaultSavedViews']

/**
 * One list item with its patch applied.
 *
 * @param {string} list The config key of the list.
 * @param {object} item The item.
 * @param {object} change The patch.
 * @return {object} The patched item.
 */
function mergePatch(list, item, change) {
	return DEEP_PATCHED_LISTS.includes(list)
		? mergeManifestDelta(item, change).manifest
		: { ...item, ...change }
}

/**
 * The name an overlay addresses a list item by.
 *
 * Header actions and widgets carry an `id`, columns a `key`, default views a
 * `slug`, quick filters only a `label`, and a column may be a bare string. The
 * first of those that exists is the name.
 *
 * @param {unknown} item A list item from a page config.
 * @return {string|undefined} Its name, or undefined when it has none.
 *
 * @spec openspec/changes/archive/2026-10-10-simple-list-and-dashboard/specs/dashboard/spec.md#REQ-DASH-025
 */
export function overlayItemName(item) {
	if (typeof item === 'string') {
		return item
	}
	return item?.id ?? item?.key ?? item?.slug ?? item?.label
}

/** The prefix of a `nav` value the instance's theming capabilities answer. */
const THEMING_PLACEHOLDER = '@theming.'

/**
 * Resolve the `nav` block of a profile: `@theming.<key>` strings become the
 * instance's own theming values, one level deep (`brand.caption`,
 * `primaryAction.label`), so no municipality is written into the app. A
 * value may list fallbacks with `|` (`@theming.emblem|@theming.logo`).
 *
 * A placeholder the capabilities do not answer resolves to an empty string,
 * which CnAppNav reads as "nothing to draw" for that field. The profile is
 * not the place to guess an instance's name.
 *
 * @param {object} nav The profile's `nav` block.
 * @param {object|null} theming The theming capabilities (`name`, `logo`, ...).
 * @return {object} A new nav block with every placeholder resolved.
 *
 * @spec openspec/specs/nav-dedup-and-grouping/spec.md#REQ-PNDG-008
 */
export function resolveNavPlaceholders(nav, theming) {
	const resolveOne = (value) => {
		const key = value.slice(THEMING_PLACEHOLDER.length)
		const answer =
			theming && typeof theming === 'object' ? theming[key] : undefined
		return typeof answer === 'string' ? answer : ''
	}
	const resolveValue = (value) => {
		if (typeof value !== 'string' || !value.startsWith(THEMING_PLACEHOLDER)) {
			return value
		}
		// `@theming.emblem|@theming.logo`: the first placeholder the instance
		// answers wins, so a set with an emblem shows it and one without falls
		// back to its wordmark. A part that is not a placeholder is a literal
		// last resort (`@theming.staffAppName|Dossiq`).
		const answer = value
			.split('|')
			.map((part) => part.trim())
			.map((part) =>
				part.startsWith(THEMING_PLACEHOLDER) ? resolveOne(part) : part,
			)
			.find((part) => part !== '')
		if (answer === undefined) {
			logger.debug(
				'structureProfile: the instance has no theming value for a nav placeholder.',
				{ placeholder: value },
			)
			return ''
		}
		return answer
	}
	const out = {}
	for (const [key, value] of Object.entries(nav || {})) {
		out[key] =
			value && typeof value === 'object' && !Array.isArray(value)
				? Object.fromEntries(
						Object.entries(value).map(([inner, innerValue]) => [
							inner,
							resolveValue(innerValue),
						]),
					)
				: resolveValue(value)
	}
	return out
}

/**
 * Build the manifest for one structure profile.
 *
 * `buildManifest` is passed in rather than imported, so this module stays free
 * of the library barrel and a spec can hand it the real implementation.
 *
 * A profile file without `menu`, `pages` and `nav` (the full one) goes through
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
 * @param {object} [context] `{ theming }`: the instance's theming
 *   capabilities, for the placeholders a profile's `nav` block may carry.
 * @return {object} The built manifest.
 *
 * @spec openspec/specs/nav-dedup-and-grouping/spec.md#REQ-PNDG-008
 */
export function buildProfiledManifest(
	buildManifest,
	base,
	fragments,
	profileFile,
	context = {},
) {
	const file = profileFile || {}
	const builtPages = buildManifest(profiledBase(base, file), fragments, profileLayout(file))
	const built = withNav(builtPages, profileNav(file, context))

	return withPageOverlays(built, Array.isArray(file.pages) ? file.pages : [])
}

/**
 * The layout keys a profile file sets, for `buildManifest`.
 *
 * @param {object} file The profile's layout file.
 * @return {object} The layout.
 */
function profileLayout(file) {
	const layout = {}
	for (const key of LAYOUT_KEYS) {
		if (file[key] !== undefined) {
			layout[key] = file[key]
		}
	}
	return layout
}

/**
 * The bundled manifest with the profile's menu entries in front of its own.
 *
 * @param {object} base The bundled manifest.
 * @param {object} file The profile's layout file.
 * @return {object} The base to build from.
 */
function profiledBase(base, file) {
	const profileMenu = Array.isArray(file.menu) ? file.menu : []
	if (profileMenu.length === 0) {
		return base
	}
	return {
		...base,
		// Copies, because buildManifest merges into the entries it is
		// given and the profile file is a shared module object.
		menu: [
			...profileMenu.map((entry) => ({ ...entry })),
			...(base.menu || []),
		],
	}
}

/**
 * The profile's nav block with its placeholders resolved, null without one.
 *
 * @param {object} file The profile's layout file.
 * @param {object} context `{ theming }`: the instance's theming capabilities.
 * @return {object|null} The nav keys.
 */
function profileNav(file, context) {
	if (!file.nav || typeof file.nav !== 'object') {
		return null
	}
	return resolveNavPlaceholders(file.nav, context.theming ?? null)
}

/**
 * The built manifest with the profile's nav keys over its own.
 *
 * @param {object} builtPages The built manifest.
 * @param {object|null} nav The profile's nav keys.
 * @return {object} The manifest.
 */
function withNav(builtPages, nav) {
	if (nav === null) {
		return builtPages
	}
	return {
		...builtPages,
		nav: {
			...(builtPages.nav || {}),
			...nav,
		},
	}
}

/**
 * The manifest with each page overlay applied to the page it names. An
 * overlay that names a page the manifest does not have is skipped and
 * reported.
 *
 * @param {object} built The built manifest.
 * @param {Array<object>} overlays The profile's page overlays.
 * @return {object} The manifest.
 */
function withPageOverlays(built, overlays) {
	if (overlays.length === 0) {
		return built
	}
	const pages = [...(built.pages || [])]
	for (const overlay of overlays) {
		overlayPage(pages, overlay)
	}
	return { ...built, pages }
}

/**
 * Apply one overlay to the page it names, in place in the page list, or
 * report it when no page has that id.
 *
 * @param {Array<object>} pages The manifest's pages, a copy.
 * @param {object} overlay One page overlay.
 */
function overlayPage(pages, overlay) {
	const at = pages.findIndex((page) => page.id === overlay?.id)
	if (at === -1) {
		logger.warn(
			'structureProfile: page overlay names a page the manifest does not have; skipped.',
			{ page: overlay?.id },
		)
		return
	}
	pages[at] = applyPageOverlay(pages[at], overlay)
}
