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

import { currentPermissions, permits } from './permissions.js'

/**
 * Build the vue-router config from the manifest.
 *
 * Each manifest page becomes one route; the route's `name` IS `page.id` (per
 * the library's manifest contract). Routes whose path declares a `:` parameter
 * receive `props: true` so the underlying detail/custom component receives the
 * route param.
 *
 * The page's declared `permission` travels with the route as `meta.permission`,
 * which is what `permissionGuard` reads. It used to be dropped on the floor
 * here, while the v2 manifest schema calls that field "the permission
 * identifier required to ACCESS this page" — so `/settings/integrations` was
 * absent from an ordinary account's navigation and rendered in full to the
 * same account the moment they typed the URL.
 *
 * @param {object} manifest  The built manifest (with `pages[]`).
 * @param {object} component The component every manifest route renders.
 *
 * @return {Array<object>} vue-router route records.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function routesFromManifest(manifest, component) {
	const routes = (manifest.pages || []).map((page) => ({
		name: page.id,
		path: page.route,
		component,
		props: page.route.includes(':'),
		meta: { permission: page.permission || '' },
	}))
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
