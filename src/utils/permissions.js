// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.

/**
 * Who the current account is allowed to be, in the vocabulary the manifest
 * uses.
 *
 * ONE source, read by TWO surfaces, because the two disagreed.
 * ------------------------------------------------------------
 * The manifest declares `permission` in two places. On a `menu[]` entry it
 * decides whether the nav renders the link; on a `pages[]` entry the schema
 * calls it "the permission identifier required to ACCESS this page". The nav
 * half was wired up. The page half was declared and enforced nowhere — not by
 * `@conduction/nextcloud-vue` (which never sees the router; the app builds it)
 * and not by `routesFromManifest()` in `main.js` (which dropped the field on
 * the floor). So `/settings/integrations` was absent from an ordinary
 * account's nav and rendered in full the moment they typed the URL: eleven
 * rows of integration status and seven `Open settings` links into
 * `/settings/admin/dossiq`.
 *
 * A gate on one of two doors is not a gate, and a gate that lives in two
 * modules drifts. Hence this file: the nav filter and the router guard now
 * read the same list.
 *
 * WHAT IT DOES NOT DO, said plainly.
 * ---------------------------------
 * This is a router guard, not an authorization boundary. It stops the page
 * from rendering; it does not stop the data being fetched by hand. The
 * integration rows live in OpenRegister and the page reads them over `GET
 * /apps/openregister/api/objects/dossiq/dossiqIntegration`, which nothing in
 * this file can narrow.
 *
 * That endpoint used to answer every row to every authenticated account,
 * because the `dossiqIntegration` schema declared no `authorization` block and
 * OpenRegister treats an absent block as open. It is closed now — the schema
 * declares `read: ["admin"]` in `lib/Settings/dossiq_register.json` — but the
 * division of labour has not changed and is the thing to keep hold of: the
 * guard below governs the page, the schema governs the data, and neither
 * substitutes for the other. A future page that reads a schema with no block
 * is open again no matter what this file says.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

/**
 * The permission strings the current account holds.
 *
 * Never empty, and that is the point rather than a detail. `CnAppNav`'s filter
 * treats an empty array as "the app did not say" and renders every item
 * regardless of what it declares:
 *
 *     if (!item.permission) return true
 *     if (!this.permissions || this.permissions.length === 0) return true
 *     return this.permissions.includes(item.permission)
 *
 * So a gate answered with `[]` fails OPEN. It used to: the only source was
 * `OC.currentUser.permissions`, and `OC.currentUser` is the uid STRING, so
 * that read has always been undefined and every ordinary account saw every
 * admin-only entry. `user` is what everyone holds and no manifest entry asks
 * for, which keeps the list non-empty for the accounts that hold nothing else.
 *
 * `admin` matches the backend `TenantService::isPlatformAdmin()` check.
 *
 * @return {Array<string>} The permission strings held, always non-empty.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function currentPermissions() {
	const isAdmin =
		typeof window.OC?.isUserAdmin === 'function'
			? window.OC.isUserAdmin()
			: false
	return isAdmin ? ['user', 'admin'] : ['user']
}

/**
 * Whether an account holding `permissions` may open something declaring
 * `required`.
 *
 * Deliberately NOT the shape `CnAppNav` uses. There is no "the app did not
 * say" escape here: an empty or absent list denies anything that asks for a
 * permission, because on a route the empty case is exactly the one that must
 * not open the door. A page that declares nothing stays public, which is what
 * every page but four does.
 *
 * @param {string}        required    The declared permission, or '' / undefined.
 * @param {Array<string>} permissions The permissions held.
 *
 * @return {boolean} True when access is allowed.
 * @spec openspec/specs/admin-settings/spec.md
 */
export function permits(required, permissions) {
	if (!required) {
		return true
	}
	if (!Array.isArray(permissions)) {
		return false
	}
	return permissions.includes(required)
}
