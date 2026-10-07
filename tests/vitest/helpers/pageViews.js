/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Read a dashboard page's widgets wherever they live.
 *
 * Since landing-views a dashboard page can hold its widgets in two places:
 * its own grid (`config.widgets` + `config.layout`) and the grid of each of
 * its views (`config.views[].widgets` + `.layout`). The library draws the
 * page's own grid first and the chosen view below it. A test that reads only
 * `config.widgets` would find nothing on the landing page and pass on an
 * empty list, so the specs read through these helpers.
 *
 * @spec openspec/changes/landing-views/specs/my-work-landing/spec.md
 */

/**
 * The grids a page draws: its own, then one per view.
 *
 * @param {object} page A manifest page.
 * @return {Array<{id: string, widgets: Array<object>, layout: Array<object>}>} The grids.
 */
export function pageGrids(page) {
	const config = (page && page.config) || {}
	const own = {
		id: 'page',
		widgets: config.widgets || [],
		layout: config.layout || [],
	}
	const views = (config.views || []).map((view) => ({
		id: view.id,
		widgets: view.widgets || [],
		layout: view.layout || [],
	}))
	return [own, ...views]
}

/**
 * Every widget a page declares, its own grid's and every view's.
 *
 * @param {object} page A manifest page.
 * @return {Array<object>} The widget definitions.
 */
export function pageWidgets(page) {
	return pageGrids(page).flatMap((grid) => grid.widgets)
}

/**
 * One view of a page.
 *
 * @param {object} page A manifest page.
 * @param {string} id The view id.
 * @return {object|undefined} The view, or undefined.
 */
export function pageView(page, id) {
	return ((page && page.config && page.config.views) || []).find(
		(view) => view.id === id,
	)
}
