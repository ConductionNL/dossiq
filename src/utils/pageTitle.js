// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The browser tab title of a dossiq page.
 *
 * Nextcloud renders the tab title once, server side, as "<app> - <instance>",
 * so every dossiq page read "Dossiq - Conduction Nextcloud" whatever was on
 * screen. A person with five dossiq tabs open could not tell them apart, and
 * a screen reader announced the same title on every route change. Nextcloud's
 * own apps put the page in front of that title ("Recent - Files - Nextcloud"),
 * and so does this: the page name, then the title the server rendered.
 *
 * The page name is the manifest page's `title`, translated. A route that names
 * no page (the catch-all redirect, a page without a title) keeps the server's
 * title as it is, rather than inventing a name.
 *
 * @spec openspec/specs/page-titles/spec.md
 */

/** What separates the page name from the rest, as Nextcloud's apps write it. */
export const TITLE_SEPARATOR = ' - '

/**
 * The manifest page a route renders.
 *
 * @param {object} manifest The built manifest (with `pages[]`).
 * @param {object} route    A vue-router route (`meta.cnPageId`, `name`).
 * @return {object|null} The page, or null when the route names none.
 * @spec openspec/specs/page-titles/spec.md
 */
export function pageForRoute(manifest, route) {
	const id = route?.meta?.cnPageId || route?.name || ''
	if (id === '') {
		return null
	}
	const pages = Array.isArray(manifest?.pages) ? manifest.pages : []
	return pages.find((page) => page?.id === id) || null
}

/**
 * The menu entry that opened a filtered case list.
 *
 * A "My case types" menu entry (backend `/api/manifest` delta) opens the Cases
 * page with `?caseType=<id>` and carries the case type's title as its label.
 * That label is the only name for the case type the route knows.
 *
 * @param {Array<object>} menu   The menu, entries may hold `children[]`.
 * @param {string}        pageId The page the route renders.
 * @param {string}        caseType The case type id in the query.
 * @return {object|null} The entry, or null when no entry opens that filter.
 * @spec openspec/specs/page-titles/spec.md
 */
export function menuEntryForCaseType(menu, pageId, caseType) {
	const entries = Array.isArray(menu) ? menu : []
	for (const entry of entries) {
		if (
			entry?.route === pageId
			&& String(entry?.query?.caseType ?? '') === caseType
		) {
			return entry
		}
		const child = menuEntryForCaseType(entry?.children, pageId, caseType)
		if (child) {
			return child
		}
	}
	return null
}

/** The name of a list narrowed to a case type the menu does not name. */
export const FILTERED_CASES_TITLE = 'Cases of one case type'

/**
 * The name of the page, or of the case type a list is narrowed to.
 *
 * @param {object} page  The manifest page.
 * @param {object} route The route being shown.
 * @param {Array<object>} menu The current menu.
 * @param {(label: string) => string} translate Translates a label.
 * @return {string} The name, or an empty string.
 * @spec openspec/specs/page-titles/spec.md
 */
function nameFor(page, route, menu, translate) {
	const raw = typeof page?.title === 'string' ? page.title.trim() : ''
	if (raw === '') {
		return ''
	}
	const caseType = String(route?.query?.caseType ?? '').trim()
	if (caseType !== '') {
		const entry = menuEntryForCaseType(menu, page.id, caseType)
		const label = typeof entry?.label === 'string' ? entry.label.trim() : ''
		const name = label !== '' ? label : FILTERED_CASES_TITLE
		return String(translate(name) || name).trim()
	}
	return String(translate(raw) || raw).trim()
}

/**
 * The tab title for one route.
 *
 * A list narrowed to one case type (`?caseType=<id>`) is named after that case
 * type ("Woo-verzoek - Dossiq - Nextcloud"), the way the menu entry that opened
 * it reads, not after the unfiltered page ("All cases"). When no menu entry
 * names the case type the tab says the list is narrowed, rather than claiming
 * it shows every case.
 *
 * @param {object}   manifest  The built manifest.
 * @param {object}   route     The route being shown.
 * @param {string}   baseTitle The title the server rendered ("Dossiq - Nextcloud").
 * @param {(label: string) => string} translate Translates a manifest label.
 * @param {Array<object>} menu The current menu (with the backend delta), defaults to the manifest's.
 * @return {string} "<page> - <baseTitle>", or `baseTitle` when the route names no page.
 * @spec openspec/specs/page-titles/spec.md
 * @spec openspec/specs/page-titles/spec.md
 */
export function pageTitleFor(
	manifest,
	route,
	baseTitle,
	translate = (s) => s,
	menu = manifest?.menu,
) {
	const page = pageForRoute(manifest, route)
	const name = nameFor(page, route, menu, translate)
	if (name === '' || baseTitle === '') {
		return name || baseTitle
	}
	return `${name}${TITLE_SEPARATOR}${baseTitle}`
}

/**
 * Keep the tab title in step with the route.
 *
 * The server's title is read ONCE, before the first route renders, so a later
 * page does not stack its name onto the previous page's ("Case - Cases - …").
 *
 * The "My case types" menu entries arrive with the backend delta, after the
 * first route has rendered, so the returned function re-titles the current
 * route; main.js calls it when the menu changes.
 *
 * @param {object}   router    The vue-router instance.
 * @param {object}   manifest  The built manifest.
 * @param {(label: string) => string} translate Translates a manifest label.
 * @param {object}   doc       The document (injectable for tests).
 * @param {() => Array<object>} currentMenu Reads the current menu.
 * @return {() => void} Re-titles the current route.
 * @spec openspec/specs/page-titles/spec.md
 * @spec openspec/specs/page-titles/spec.md
 */
export function installPageTitles(
	router,
	manifest,
	translate,
	doc = document,
	currentMenu = () => manifest?.menu,
) {
	const baseTitle = String(doc?.title || '')
	const apply = (to) => {
		doc.title = pageTitleFor(manifest, to, baseTitle, translate, currentMenu())
	}
	router.afterEach(apply)
	return () => apply(router.currentRoute?.value)
}
