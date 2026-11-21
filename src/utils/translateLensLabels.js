// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Run the label of every quick filter (lens), and the footer note and bulk
 * hint of a list, through the host translate.
 *
 * The footer note and the bulk hint are drawn as written too.
 *
 * nextcloud-vue draws a lens's `label` as written: CnQuickFilterBar does not
 * pass it through `cnTranslate` the way it does a column label, so under the
 * board look a Dutch reader got "Mine" and "Due this week" over a Dutch page.
 * The labels are the English source strings of this manifest, so translating
 * them once, before the manifest reaches the renderer, gives the same text a
 * library-side translate would. Lenses that are not strings are left alone.
 *
 * @param {object} manifest The built manifest.
 * @param {(text: string) => string} translate The host translate.
 * @return {object} The same manifest with its lens labels translated.
 *
 * @spec openspec/changes/archive/2026-10-10-simple-list-and-dashboard/design.md
 */
export function translateLensLabels(manifest, translate) {
	for (const page of manifest?.pages ?? []) {
		translatePageNotes(page, translate)
		translateQuickFilters(page, translate)
	}
	return manifest
}

/** The page config notes nextcloud-vue draws as written. */
const PAGE_NOTE_KEYS = ['footerNote', 'bulkHint']

/**
 * Translate a page's footer note and bulk hint, when they are strings.
 *
 * @param {object|null} page One page of the manifest.
 * @param {(text: string) => string} translate The host translate.
 */
function translatePageNotes(page, translate) {
	for (const key of PAGE_NOTE_KEYS) {
		if (typeof page?.config?.[key] === 'string') {
			page.config[key] = translate(page.config[key])
		}
	}
}

/**
 * Translate the string label of each of a page's quick filters.
 *
 * @param {object|null} page One page of the manifest.
 * @param {(text: string) => string} translate The host translate.
 */
function translateQuickFilters(page, translate) {
	const lenses = page?.config?.quickFilters
	if (!Array.isArray(lenses)) {
		return
	}
	page.config.quickFilters = lenses.map((lens) =>
		lens && typeof lens.label === 'string'
			? { ...lens, label: translate(lens.label) }
			: lens,
	)
}

/** The widget copy keys nextcloud-vue draws as written. */
const WIDGET_TEXT_KEYS = ['caption', 'viewAllLabel', 'emptyText']

/** The banner copy keys nextcloud-vue draws as written. */
const BANNER_TEXT_KEYS = ['kicker', 'title', 'reason', 'text']

/** The tab strip keys CnTabsWidget draws as written. */
const TAB_TEXT_KEYS = ['moreLabel', 'ariaLabel']

/**
 * Run the labels of a tab strip and of a sections panel through the host
 * translate.
 *
 * CnTabsWidget draws `tabs[].label`, `moreLabel` and `ariaLabel` as written,
 * and the library sections widget draws `sections[].label` as written, so on a Dutch
 * page the case's tabs read Overview, Documents, Contact, Tasks, History and
 * the panels under them Core case data, Woo publication, although every one of
 * those strings has its Dutch entry in this app's catalogue (finding F4,
 * dutch-copy-on-the-case-page).
 *
 * @param {object} content A widget's content.
 * @param {(text: string) => string} translate The host translate.
 * @return {void}
 *
 * @spec openspec/specs/frontend-locale-completeness/spec.md
 */
function translateTabsAndSections(content, translate) {
	for (const key of TAB_TEXT_KEYS) {
		if (typeof content[key] === 'string') {
			content[key] = translate(content[key])
		}
	}
	for (const listKey of ['tabs', 'sections']) {
		if (!Array.isArray(content[listKey])) {
			continue
		}
		content[listKey] = content[listKey].map((entry) =>
			entry && typeof entry.label === 'string'
				? { ...entry, label: translate(entry.label) }
				: entry,
		)
	}
}

/**
 * Run the copy of every banner widget through the host translate, and the
 * captions, link texts, tab labels and section labels of every other widget.
 *
 * CnBannerWidget draws `kicker`, `title`, `reason` and the action labels of
 * a banner as written, so "First today" and "Open the board" stayed English
 * on a Dutch page while the stat tiles beside them were Dutch. Placeholders
 * such as `{value}` stay in the text, the widget fills them.
 *
 * @param {object} manifest The built manifest.
 * @param {(text: string) => string} translate The host translate.
 * @return {object} The same manifest with its banner copy translated.
 *
 * @spec openspec/changes/archive/2026-10-10-simple-list-and-dashboard/design.md
 */
export function translateBannerCopy(manifest, translate) {
	const banner = (widget) => {
		const content = widget?.content
		if (!content) {
			return
		}
		// Stat captions ("+{newToday} today") and table link texts are drawn as
		// written as well; placeholders stay in the text, the widget fills them.
		for (const key of WIDGET_TEXT_KEYS) {
			if (typeof content[key] === 'string') {
				content[key] = translate(content[key])
			}
		}
		translateTabsAndSections(content, translate)
		// CnCountdownWidget draws its `label` as written, so the case page's
		// Termijn tile read "Deadline" on a Dutch page (finding N-3).
		if (widget?.type === 'countdown' && typeof content.label === 'string') {
			content.label = translate(content.label)
		}
		if (widget?.type !== 'banner') {
			return
		}
		for (const key of BANNER_TEXT_KEYS) {
			if (typeof content[key] === 'string') {
				content[key] = translate(content[key])
			}
		}
		for (const action of content.actions ?? []) {
			if (typeof action?.label === 'string') {
				action.label = translate(action.label)
			}
		}
	}
	for (const page of manifest?.pages ?? []) {
		const config = page?.config ?? {}
		for (const widget of config.widgets ?? []) {
			banner(widget)
		}
		for (const view of Object.values(config.views ?? {})) {
			for (const widget of view?.widgets ?? []) {
				banner(widget)
			}
		}
	}
	return manifest
}

/** The keys under which a manifest declares per-field overrides. */
const FIELD_OVERRIDE_KEYS = ['fieldOverrides', 'overrides']

/**
 * Run the `label` of every field override in the manifest through the host
 * translate.
 *
 * nextcloud-vue's `fieldsFromSchema()` translates a schema property title, and
 * then copies an override over the field as written. So an override that
 * renames a field, such as `requester: { label: "Requester" }` on the create
 * form, the edit form and the Overview tab, put English back over a label the
 * schema had already given in Dutch (finding N-3). The walk covers every
 * place an override map sits: a page config, a header action's create form,
 * the nav's primary action, and a data widget's content.
 *
 * @param {object} manifest The built manifest.
 * @param {(text: string) => string} translate The host translate.
 * @return {object} The same manifest with its override labels translated.
 *
 * @spec openspec/specs/frontend-locale-completeness/spec.md
 */
export function translateFieldOverrideLabels(manifest, translate) {
	walkOverrides({ seen: new Set(), translate }, manifest)
	return manifest
}

/**
 * Walk one node of the manifest, translating every override map under it.
 * A node is walked once, so a shared or cyclic node is not visited again.
 *
 * @param {{seen: Set<object>, translate: (text: string) => string}} run The walked nodes and the host translate.
 * @param {unknown} node The node.
 */
function walkOverrides(run, node) {
	if (!node || typeof node !== 'object' || run.seen.has(node)) {
		return
	}
	run.seen.add(node)
	if (Array.isArray(node)) {
		node.forEach((child) => walkOverrides(run, child))
		return
	}
	walkEntries(run, node)
}

/**
 * Translate the override maps an object holds, and walk each of its values,
 * key by key.
 *
 * @param {{seen: Set<object>, translate: (text: string) => string}} run The walked nodes and the host translate.
 * @param {object} node The object.
 */
function walkEntries(run, node) {
	for (const [key, value] of Object.entries(node)) {
		if (isOverrideMap(key, value)) {
			translateOverrides(value, run.translate)
		}
		walkOverrides(run, value)
	}
}

/**
 * Whether a key holds a map of per-field overrides.
 *
 * @param {string} key The key.
 * @param {unknown} value Its value.
 * @return {boolean} True for an override map.
 */
function isOverrideMap(key, value) {
	return FIELD_OVERRIDE_KEYS.includes(key)
		&& value
		&& typeof value === 'object'
		&& !Array.isArray(value)
}

/**
 * Translate the string label of each override in a map, in place.
 *
 * @param {object} overrides The override map.
 * @param {(text: string) => string} translate The host translate.
 */
function translateOverrides(overrides, translate) {
	for (const [field, override] of Object.entries(overrides)) {
		if (override && typeof override.label === 'string') {
			overrides[field] = {
				...override,
				label: translate(override.label),
			}
		}
	}
}
