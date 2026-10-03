/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

/**
 * A section's widget with the cell budget switched off.
 *
 * An object list fits its visible rows to the host grid cell, measured from
 * where its table STARTS to the cell's bottom (ADR-062). That is right for a
 * list that owns its cell and wrong for one stacked below another section in
 * the same cell. Measured on a live case page: the Objects list, third section
 * of Related, began 584px down a 696px cell, the budget floored to one row, and
 * one of the two objects the server returned simply did not render.
 *
 * Every widget in this container shares the cell by construction, and on a
 * detail page the cell is `overflow: auto`, so the right answer is to render
 * every fetched row and let the cell scroll. `limit` still caps the fetch and
 * the pager still pages.
 *
 * Set here rather than on each manifest section, because the next list moved
 * below another one is the one whose author will not know to add it. A widget
 * that already says `fit` keeps what it says. The manifest object is copied,
 * never mutated: the same definition is read again on every render.
 *
 * `content.fit` is honoured by @conduction/nextcloud-vue from the release
 * carrying nextcloud-vue#1151; on an older one the key is ignored, so this is
 * harmless until then rather than wrong.
 *
 * @param {object} widget A section's widget definition.
 * @return {object} The same definition with `content.fit` defaulted to false.
 * @spec openspec/specs/case-dashboard-view/spec.md
 */
export function unfittedWidget(widget) {
	const content = widget.content || {}
	if (Object.hasOwn(content, 'fit')) {
		return widget
	}
	return { ...widget, content: { ...content, fit: false } }
}
