// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * The manifest's `pages[]` turned into a vue-router table, and the guard that
 * enforces what those pages declare.
 *
 * Both halves live here rather than in `main.js` for one reason: a permission
 * check that nothing can test is a permission check nobody watched fail.
 * `main.js` runs `createApp`, installs Pinia and touches `window` on import,
 * so a unit test cannot reach the two functions below through it, and the
 * route half of the manifest's `permission` field went unenforced for as long
 * as it did partly because nothing was in a position to notice.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import { buildManifestRoutes } from '@conduction/nextcloud-vue'
import { currentPermissions, permits } from './permissions.js'

/**
 * Build the vue-router config from the manifest.
 *
 * The records come from the library's `buildManifestRoutes()`, not from a
 * `pages.map()` of our own. That matters for more than tidiness: an index page
 * declaring `splitView` needs a SECOND address, `/<route>/split/:id`, mounting
 * the same page so the list never unmounts and never loses its scroll
 * position. A hand-written table emits one record per page and can never
 * produce it, so the split view would open nothing and the console would say
 * so only in a warning nobody reads.
 *
 * Two things the library cannot know are added here through its `decorate`
 * hook, which is exactly what the hook is for:
 *
 * - `meta.permission`, the page's declared permission, which `permissionGuard`
 *   reads. The v2 schema calls that field "the permission identifier required
 *   to ACCESS this page", and dropping it is how `/settings/integrations` came
 *   to render in full to any account that typed the URL. It travels onto the
 *   split record too, because a split address is the same page.
 * - `props: true` for a path that declares a `:` parameter, so the underlying
 *   detail or custom component receives the route param.
 *
 * The catch-all redirect is ours as well: `buildManifestRoutes()` returns the
 * manifest's pages and nothing else.
 *
 * @param {object} manifest  The built manifest (with `pages[]`).
 * @param {object} component The component every manifest route renders.
 *
 * @return {Array<object>} vue-router route records.
 * @spec openspec/changes/case-page-and-list-as-a-place/specs/case-management/spec.md
 */
export function routesFromManifest(manifest, component) {
	const routes = buildManifestRoutes(manifest, {
		component,
		decorate: (page, record) => ({
			...record,
			props: String(record.path || '').includes(':'),
			meta: {
				...record.meta,
				permission: page.permission || '',
			},
		}),
	})
	// Catch-all redirect to dashboard, preserving prior router behaviour.
	// vue-router 4 syntax: the bare '*' catch-all became a named param matcher.
	routes.push({ path: '/:pathMatch(.*)*', redirect: '/' })
	return routes
}

/**
 * The other half of the manifest's `permission` field.
 *
 * `CnAppNav` filters the MENU entry. Nothing filtered the ROUTE — not this app,
 * and not `@conduction/nextcloud-vue`, which never sees the router because the
 * app builds it. So every page marked admin-only was one typed URL away from
 * anyone holding an account.
 *
 * A redirect rather than an error page: the entry is already absent from the
 * navigation, so the only ways here are a hand-typed URL and a link that
 * outlived somebody's group membership. Both want the dashboard, which is also
 * where the catch-all sends an unmatched path.
 *
 * READ IT FOR WHAT IT IS. This stops the PAGE, not the data behind it. The
 * rows come from OpenRegister's object API, which answers any authenticated
 * account and is narrowed by a schema `authorization` block, not by anything
 * in this bundle.
 *
 * @param {object} to The route being entered.
 *
 * @return {boolean|object} True to allow, or the redirect target.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function permissionGuard(to) {
	if (permits(to?.meta?.permission, currentPermissions())) {
		return true
	}
	return { path: '/' }
}
