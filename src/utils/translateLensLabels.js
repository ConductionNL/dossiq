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
 * @spec openspec/changes/simple-list-and-dashboard/design.md
 */
export function translateLensLabels(manifest, translate) {
	for (const page of manifest?.pages ?? []) {
		for (const key of ['footerNote', 'bulkHint']) {
			if (typeof page?.config?.[key] === 'string') {
				page.config[key] = translate(page.config[key])
			}
		}
		const lenses = page?.config?.quickFilters
		if (!Array.isArray(lenses)) {
			continue
		}
		page.config.quickFilters = lenses.map((lens) =>
			lens && typeof lens.label === 'string'
				? { ...lens, label: translate(lens.label) }
				: lens,
		)
	}
	return manifest
}

/** The banner copy keys nextcloud-vue draws as written. */
const BANNER_TEXT_KEYS = ['kicker', 'title', 'reason', 'text']

/**
 * Run the copy of every banner widget through the host translate.
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
 * @spec openspec/changes/simple-list-and-dashboard/design.md
 */
export function translateBannerCopy(manifest, translate) {
	const banner = (widget) => {
		const content = widget?.content
		if (widget?.type !== 'banner' || !content) {
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
