// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Run the label of every quick filter (lens) through the host translate.
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
		const lenses = page?.config?.quickFilters
		if (!Array.isArray(lenses)) {
			continue
		}
		page.config.quickFilters = lenses.map((lens) => (
			lens && typeof lens.label === 'string'
				? { ...lens, label: translate(lens.label) }
				: lens
		))
	}
	return manifest
}
