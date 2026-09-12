/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Who can OPEN an admin-only page, as opposed to who can see it in the menu.
 *
 * The manifest declares `permission` twice, and only one of the two was ever
 * enforced. On a `menu[]` entry it hides the link, and `appNavPermissions.spec.js`
 * guards that. On a `pages[]` entry the v2 schema calls it "the permission
 * identifier required to ACCESS this page" — and nothing read it: not
 * `@conduction/nextcloud-vue`, which never sees the router because the app
 * builds it, and not `routesFromManifest()`, which dropped the field. So
 * `/settings/integrations` was absent from an ordinary account's navigation
 * and rendered in full — eleven integration rows, seven `Open settings` links
 * into `/settings/admin/dossiq` — the moment that account typed the URL.
 *
 * The last test in this file is the one that would have caught the other three
 * open doors: `Tenants`, `TenantDetail` and `SubstitutionAdmin` all had an
 * admin-gated MENU entry and an ungated PAGE, so gating the route alone would
 * have closed one surface out of four and looked finished.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import {
	permissionGuard,
	routesFromManifest,
} from '../../src/utils/manifestRoutes.js'
import { currentPermissions, permits } from '../../src/utils/permissions.js'

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(
	fs.readFileSync(path.join(ROOT, 'src', 'manifest.json'), 'utf8'),
)

const STUB = {}

/**
 * Stub the Nextcloud admin flag the way the browser exposes it.
 *
 * @param {boolean} isAdmin Whether the account is in the admin group.
 */
function asAccount(isAdmin) {
	globalThis.window = { OC: { isUserAdmin: () => isAdmin } }
}

beforeEach(() => {
	asAccount(false)
})

afterEach(() => {
	delete globalThis.window
})

describe('permits', () => {
	it('lets anything through that asks for nothing', () => {
		expect(permits('', ['user'])).toBe(true)
		expect(permits(undefined, ['user'])).toBe(true)
	})

	it('lets a holder through', () => {
		expect(permits('admin', ['user', 'admin'])).toBe(true)
	})

	it('refuses a non-holder', () => {
		expect(permits('admin', ['user'])).toBe(false)
	})

	it('refuses on an EMPTY list rather than failing open', () => {
		// This is the difference from `CnAppNav.passesPermission`, which reads
		// an empty array as "the app did not say" and renders the item. On a
		// nav entry that is a visibility bug; on a route it would be the whole
		// gate, so the empty case has to deny.
		expect(permits('admin', [])).toBe(false)
		expect(permits('admin', null)).toBe(false)
		expect(permits('admin', undefined)).toBe(false)
	})
})

describe('currentPermissions', () => {
	it('is never empty, so no downstream filter can read it as "unset"', () => {
		asAccount(false)
		expect(currentPermissions().length).toBeGreaterThan(0)
	})

	it('withholds admin from an ordinary account', () => {
		asAccount(false)
		expect(currentPermissions()).not.toContain('admin')
	})

	it('grants admin to the admin group', () => {
		asAccount(true)
		expect(currentPermissions()).toContain('admin')
	})
})

describe('routesFromManifest', () => {
	it('carries every page permission onto its route', () => {
		const routes = routesFromManifest(
			{
				pages: [
					{ id: 'Open', route: '/open', type: 'index' },
					{
						id: 'Shut',
						route: '/shut',
						type: 'index',
						permission: 'admin',
					},
				],
			},
			STUB,
		)
		const byName = Object.fromEntries(
			routes.filter((r) => r.name).map((r) => [r.name, r]),
		)
		expect(byName.Open.meta.permission).toBe('')
		expect(byName.Shut.meta.permission).toBe('admin')
	})

	it('still ends in the catch-all redirect', () => {
		const routes = routesFromManifest({ pages: [] }, STUB)
		expect(routes[routes.length - 1].redirect).toBe('/')
	})
})

describe('permissionGuard', () => {
	it('opens an ungated route for an ordinary account', () => {
		asAccount(false)
		expect(permissionGuard({ meta: { permission: '' } })).toBe(true)
	})

	it('redirects an ordinary account away from an admin route', () => {
		asAccount(false)
		expect(permissionGuard({ meta: { permission: 'admin' } })).toEqual({
			path: '/',
		})
	})

	it('opens the same route for an admin', () => {
		asAccount(true)
		expect(permissionGuard({ meta: { permission: 'admin' } })).toBe(true)
	})
})

describe('the shipped manifest', () => {
	it('gates the Integrations route on admin', () => {
		const routes = routesFromManifest(manifest, STUB)
		const integrations = routes.find((r) => r.name === 'Integrations')
		expect(integrations.path).toBe('/settings/integrations')
		expect(integrations.meta.permission).toBe('admin')
	})

	it('turns every gated page away from an ordinary account', () => {
		asAccount(false)
		const gated = routesFromManifest(manifest, STUB).filter(
			(r) => r.meta && r.meta.permission,
		)
		expect(gated.length).toBeGreaterThan(0)
		for (const route of gated) {
			expect(
				permissionGuard(route),
				`${route.name} (${route.path}) declares ${route.meta.permission} and must not open`,
			).toEqual({ path: '/' })
		}
	})

	it('lets an admin into every one of them', () => {
		asAccount(true)
		const gated = routesFromManifest(manifest, STUB).filter(
			(r) => r.meta && r.meta.permission,
		)
		for (const route of gated) {
			expect(
				permissionGuard(route),
				`${route.name} must open for an admin`,
			).toBe(true)
		}
	})

	it('gates the PAGE wherever it gates the MENU ENTRY', () => {
		// The declaration that hides a link and the declaration that closes a
		// route are the same claim, and they were out of step on three of the
		// four gated entries: `TenantsMenu`, `SubstitutionAdminMenu` and the
		// tenant detail page behind the first. Hiding a link is not access
		// control; a menu entry gated on `admin` whose page is gated on nothing
		// is an admin surface with a hand-typed URL for a key.
		const byRouteName = Object.fromEntries(
			(manifest.pages || []).map((page) => [page.id, page]),
		)
		const walk = (items) => {
			for (const item of items || []) {
				if (item.permission && item.route) {
					const page = byRouteName[item.route]
					expect(page, `menu ${item.id} points at no page`).toBeTruthy()
					expect(
						page.permission,
						`menu ${item.id} is gated on ${item.permission} but page ${page.id} (${page.route}) is not`,
					).toBe(item.permission)
				}
				walk(item.children)
			}
		}
		walk(manifest.menu)
	})
})
