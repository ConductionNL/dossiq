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
 * @spec openspec/changes/r6-dossiq-titles-related-cases-requests/specs/page-titles/spec.md
 */

/** What separates the page name from the rest, as Nextcloud's apps write it. */
export const TITLE_SEPARATOR = ' - '

/**
 * The manifest page a route renders.
 *
 * @param {object} manifest The built manifest (with `pages[]`).
 * @param {object} route    A vue-router route (`meta.cnPageId`, `name`).
 * @return {object|null} The page, or null when the route names none.
 * @spec openspec/changes/r6-dossiq-titles-related-cases-requests/specs/page-titles/spec.md
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
 * The tab title for one route.
 *
 * @param {object}   manifest  The built manifest.
 * @param {object}   route     The route being shown.
 * @param {string}   baseTitle The title the server rendered ("Dossiq - Nextcloud").
 * @param {Function} translate Translates a manifest label.
 * @return {string} "<page> - <baseTitle>", or `baseTitle` when the route names no page.
 * @spec openspec/changes/r6-dossiq-titles-related-cases-requests/specs/page-titles/spec.md
 */
export function pageTitleFor(manifest, route, baseTitle, translate = (s) => s) {
	const page = pageForRoute(manifest, route)
	const raw = typeof page?.title === 'string' ? page.title.trim() : ''
	if (raw === '') {
		return baseTitle
	}
	const name = String(translate(raw) || raw).trim()
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
 * @param {object}   router    The vue-router instance.
 * @param {object}   manifest  The built manifest.
 * @param {Function} translate Translates a manifest label.
 * @param {object}   doc       The document (injectable for tests).
 * @return {void}
 * @spec openspec/changes/r6-dossiq-titles-related-cases-requests/specs/page-titles/spec.md
 */
export function installPageTitles(router, manifest, translate, doc = document) {
	const baseTitle = String(doc?.title || '')
	router.afterEach((to) => {
		doc.title = pageTitleFor(manifest, to, baseTitle, translate)
	})
}
