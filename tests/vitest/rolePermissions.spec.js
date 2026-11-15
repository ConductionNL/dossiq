/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The role permissions that open the data breach register and the social
 * domain pages to the privacy officers and the social-domain staff. The
 * server names the roles a user holds in the `role_permissions` initial state;
 * an administrator holds them all. A page, menu entry or page tab declaring a
 * role is hidden from everybody else.
 *
 * @spec openspec/specs/admin-data-access/spec.md
 */

import fs from 'fs'
import path from 'path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

let state = {}

vi.mock('@nextcloud/initial-state', () => ({
	loadState: (app, key, fallback) => (key in state ? state[key] : fallback),
}))

const { currentPermissions, permits, ROLE_PERMISSIONS } = await import('../../src/utils/permissions.js')

const ROOT = path.resolve(__dirname, '../..')
const manifest = JSON.parse(fs.readFileSync(path.join(ROOT, 'src/manifest.json'), 'utf8'))
const php = fs.readFileSync(path.join(ROOT, 'lib/Service/Access/RolePermissions.php'), 'utf8')

/**
 * Stub the Nextcloud admin flag the way the browser exposes it.
 *
 * @param {boolean} isAdmin Whether the account is in the admin group.
 */
function asAccount(isAdmin) {
	globalThis.window = { OC: { isUserAdmin: () => isAdmin } }
}

beforeEach(() => {
	state = {}
	asAccount(false)
})

afterEach(() => {
	delete globalThis.window
})

describe('the role permissions', () => {
	it('adds the roles the server names, and only known ones', () => {
		state.role_permissions = ['privacy', 'made-up', 3]
		expect(currentPermissions()).toEqual(['user', 'privacy'])
	})

	it('gives an ordinary account no role without the state', () => {
		expect(currentPermissions()).toEqual(['user'])
	})

	it('gives an administrator every role', () => {
		asAccount(true)
		expect(currentPermissions()).toEqual(['user', 'admin', 'privacy', 'sociaal-domein'])
	})

	it('names the same roles as the server', () => {
		for (const role of ROLE_PERMISSIONS) {
			expect(php).toContain(`=> '${role}'`)
		}
	})
})

describe('the pages the roles open', () => {
	const page = (id) => manifest.pages.find((entry) => entry.id === id)
	const menu = (id) => manifest.menu.find((entry) => entry.id === id)

	it('opens the data breach register to the privacy officers', () => {
		expect(page('AvgIncidents').permission).toBe('privacy')
		expect(menu('AvgIncidentsMenu').permission).toBe('privacy')
		expect(permits('privacy', ['user', 'privacy'])).toBe(true)
		expect(permits('privacy', ['user', 'sociaal-domein'])).toBe(false)
	})

	it('shows the Data breaches tab on the data subject requests view only to them', () => {
		const view = page('Cases').config.defaultSavedViews.find((entry) => entry.slug === 'data-subject-requests')
		const tab = view.overrides.pageTabs.find((entry) => entry.route === 'AvgIncidents')
		expect(tab.permission).toBe('privacy')
	})

	it.each(['Indicatiestellingen', 'MdoOverleggen'])(
		'opens %s to the social-domain staff',
		(id) => {
			expect(page(id).permission).toBe('sociaal-domein')
			expect(menu(`${id}Menu`).permission).toBe('sociaal-domein')
		},
	)
})
